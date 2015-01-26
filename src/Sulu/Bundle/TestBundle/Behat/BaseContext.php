<?php
/*
 * This file is part of the Sulu CMS.
 *
 * (c) MASSIVE ART WebServices GmbH
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace Sulu\Bundle\TestBundle\Behat;

use Behat\Behat\Context\Context;
use Behat\Behat\Hook\Scope\BeforeScenarioScope;
use Behat\Symfony2Extension\Context\KernelAwareContext;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpKernel\KernelInterface;
use Symfony\Component\Security\Core\SecurityContextInterface;
use Symfony\Component\Security\Core\User\UserInterface;
use Doctrine\Common\DataFixtures\Purger\ORMPurger;
use Behat\MinkExtension\Context\MinkContext;
use Symfony\Component\Console\Output\NullOutput;
use Behat\MinkExtension\Context\RawMinkContext;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\StreamOutput;
use Sulu\Bundle\SecurityBundle\Entity\User;

/**
 * Base context extended by all Sulu Behat contexts
 * Note this context does not and should not contain any specifications.
 * It is the base class of all Contexts.
 */
abstract class BaseContext extends RawMinkContext implements Context, KernelAwareContext
{
    const LONG_WAIT_TIME = 30000;
    const MEDIUM_WAIT_TIME = 5000;

    /**
     * @var KernelInterface
     */
    protected $kernel;

    /**
     * {@inheritdoc}
     */
    public function setKernel(KernelInterface $kernel)
    {
        $this->kernel = $kernel;
    }

    /**
     * Return the user ID.
     *
     * This currently could be any integer I believe
     *
     * @return integer
     */
    protected function getUserId()
    {
        return 1;
    }

    /**
     * Execute a symfony command
     * 
     * $this->executeCommand('sulu:security:user:create', array(
     *     'firstName' => 'foo',
     *     '--option' => 'bar',
     * ));
     *
     * @param string $command Command to execute
     * @param array $args Arguments and options
     *
     * @return integer Exit code of command
     */
    protected function execCommand($command, $args)
    {
        $kernel = $this->kernel;

        array_unshift($args, $command);
        $input = new ArrayInput($args);

        $application = new Application($kernel);
        foreach ($kernel->getBundles() as $bundle) {
            $bundle->registerCommands($application);
        }

        $application->setAutoExit(false);
        $application->setCatchExceptions(false);
        $command = $application->find($command);

        $output = new StreamOutput(fopen('php://memory', 'w', false));
        $exitCode = $application->run($input, $output);

        if ($exitCode !== 0) {
            rewind($output->getStream());
            $output = stream_get_contents($output->getStream());

            throw new \Exception(sprintf(
                'Command in BaseContext exited with code "%s": "%s"',
                $exitCode, $output
            ));
        }

        return $exitCode;
    }

    /**
     * Get entity manager.
     *
     * @return ObjectManager
     */
    protected function getEntityManager()
    {
        return $this->getService('doctrine')->getManager();
    }

    /**
     * Return the PHPCR session
     */
    protected function getPhpcrSession()
    {
        return $this->getService('sulu.phpcr.session')->getSession();
    }

    /**
     * Returns Container instance.
     *
     * @return ContainerInterface
     */
    protected function getContainer()
    {
        return $this->kernel->getContainer();
    }

    /**
     * Return the named service from the DI container
     *
     * @return mixed
     */
    protected function getService($serviceId)
    {
        return $this->getContainer()->get($serviceId);
    }

    /**
     * Click the named selector
     *
     * @param string $selector
     */
    protected function clickSelector($selector)
    {
        $this->waitForSelectorAndAssert($selector);
        $script = '$("' . $selector . '").click();';
        $this->getSession()->executeScript($script);
    }

    /**
     * Return the script for clicking by title.
     *
     * @param string $selector in which the target text should be found
     * @param string $itemTitle Title of text to click within the selector
     * @param string $type Type of click (i.e. click or dblclick)
     *
     * @return string The script
     */
    protected function clickByTitle($selector, $itemTitle, $type = 'click')
    {
        $script = <<<EOT
var f = function () {
    var event = new MouseEvent('%s', {
        'view': window,
        'bubbles': true,
        'cancelable': true
    });

    var items = document.querySelectorAll("%s");

    for (var i = 0; i < items.length; i++) {
        if (items[i].textContent == '%s') {
            items[i].dispatchEvent(event);
            return;
        }
    };
}

f();
EOT;

        $script = sprintf($script, $type, $selector, $itemTitle);
        try {
            $this->getSession()->executeScript($script);
        } catch (\Exception $e) {
            var_dump($e->getMessage());die();;
        }
    }

    /**
     * Wait for the named selector to appear
     *
     * @param string $selector Selector to wait for
     * @param integer $time Timeout in miliseconds to wait
     */
    protected function waitForSelector($selector, $time = self::LONG_WAIT_TIME)
    {
        $this->getSession()->wait($time, "document.querySelectorAll(\"" . $selector . "\").length");
    }

    /**
     * Wait for the named selector to appear and produce an
     * error if it has not appeared after the timeout has been
     * exceeded.
     *
     * @param string $selector Selector to wait for
     * @param integer $time Timeout in miliseconds to wait
     */
    protected function waitForSelectorAndAssert($selector, $time = self::LONG_WAIT_TIME)
    {
        $this->waitForSelector($selector, $time);
        $this->assertSelector($selector);
    }

    /**
     * Wait for the given text to appear
     *
     * @param string $text
     * @param integer $time Timeout in miliseconds
     */
    protected function waitForText($text, $time = 10000)
    {
        $script = sprintf("$(\"*:contains(\\\"%s\\\")\").length", $text);
        $this->getSession()->wait($time, $script);
    }

    /**
     * Wait for the given text to ppear and produce an error if it
     * has not appeared after the timeout has been exceeded.
     *
     * @param string $text
     */
    protected function waitForTextAndAssert($text)
    {
        $this->waitForText($text);
        $script = sprintf("$(\"*:contains(\\\"%s\\\")\").length", $text);
        $res = $this->getSession()->evaluateScript($script);

        if (!$res) {
            throw new \Exception(sprintf('Page does not contain text "%s"', $text));
        }
    }

    /**
     * Assert that the selector appears the given number of times
     *
     * @param string $selector
     * @param integer $count Number of times the selector is expected to appear
     */
    protected function assertNumberOfElements($selector, $count)
    {
        $actual = $this->getSession()->evaluateScript('$("' . $selector . '").length');

        if ($actual != $count) {
            throw new \InvalidArgumentException(sprintf(
                'Expected "%s" items but got "%s"', $count, $actual
            ));
        }
    }

    /**
     * Assert that the given selector is present
     *
     * @param string $selector
     */
    protected function assertSelector($selector)
    {
        $res = $this->getSession()->evaluateScript("$(\"" . $selector . "\").length");

        if (!$res) {
            throw new \Exception(sprintf(
                'Failed asserting selector "%s" exists on page',
                $selector
            ));
        }
    }

    /**
     * Assert that at least one of the given selectors is present
     *
     * @param array $selectors Array of selectors
     */
    protected function assertAtLeastOneSelectors($selectors)
    {
        foreach ($selectors as $selector) {
            try {
                return $this->assertSelector($selector);
            } catch (\Exception $e) {
                continue;
            }
        }

        throw new \Exception(sprintf('Could not find any of the selectors: "%s"',
            implode('", "', $selectors)
        ));
    }

    /**
     * Set the value of the named selector
     *
     * @param string $selector
     * @param mixed $value
     */
    protected function fillSelector($selector, $value)
    {
        $this->getSession()->executeScript(sprintf(<<<EOT
var els = document.querySelectorAll("%s");
for (var i in els) {
    var el = els[i];
    el.value = '%s';
}
EOT
        , $selector, $value));
    }

    /**
     * Wait for the named aura events
     *
     * @param array Array of event names
     * @param integer Timeout in milliseconds
     */
    protected function waitForAuraEvents($eventNames, $time = self::MEDIUM_WAIT_TIME)
    {
        $script = array();
        $uniq = uniqid();
        $varNames = array();

        foreach(array_keys($eventNames) as $i) {
            $varName = 'document.__behatvar' . $uniq . $i;
            $varNames[$i] = $varName;
            $script[] = sprintf('%s = false;', $varName);
        }

        foreach ($eventNames as $i => $eventName) {
            $varName = $varNames[$i];
            $script[] = sprintf("app.sandbox.on('%s', function () { %s = true; });",
                $eventName,
                $varName
            );
            $script[] = 'console.log("' . $eventName . '");';
        }

        $script = implode("\n", $script);
        $assertion = implode(' && ', $varNames);

        $this->getSession()->executeScript($script);
        $this->getSession()->wait($time, $assertion);
    }
}