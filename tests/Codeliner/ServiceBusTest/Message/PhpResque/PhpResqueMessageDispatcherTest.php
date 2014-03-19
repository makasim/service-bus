<?php
/*
 * This file is part of the codeliner/php-service-bus.
 * (c) Alexander Miertsch <kontakt@codeliner.ws>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 * 
 * Date: 16.03.14 - 18:55
 */

namespace Codeliner\ServiceBusTest\Message\PhpResque;

use Codeliner\ServiceBus\Service\ServiceBusConfiguration;
use Codeliner\ServiceBus\Service\ServiceBusManager;
use Codeliner\ServiceBus\Service\StaticServiceBusRegistry;
use Codeliner\ServiceBusTest\Mock\RemoveFileCommand;
use Codeliner\ServiceBusTest\TestCase;
use Zend\EventManager\EventInterface;
use Zend\EventManager\StaticEventManager;

/**
 * Class PhpResqueMessageDispatcherTest
 *
 * @package Codeliner\ServiceBusTest\Message\PhpResque
 * @author Alexander Miertsch <kontakt@codeliner.ws>
 */
class PhpResqueMessageDispatcherTest extends TestCase
{
    protected $testFile;

    protected $orgDirPermissions;

    protected function setUp()
    {
        $this->testFile = __DIR__ . '/delete-me.txt';

        $this->orgDirPermissions = fileperms(__DIR__);

        chmod(__DIR__, 0770);

        file_put_contents($this->testFile, 'I am just a testfile. You can delete me.');
    }

    protected function tearDown()
    {
        StaticServiceBusRegistry::reset();

        @unlink($this->testFile);

        chmod(__DIR__, $this->orgDirPermissions);
    }

    /**
     * @test
     */
    public function it_sends_remove_file_command_to_file_remover_via_php_resque()
    {
        $this->assertTrue(file_exists($this->testFile));

        $config = new ServiceBusConfiguration(array(
            'command_bus' => array(
                'php-resque-test-bus' => array(
                    'queue' => 'php-resque-test-queue',
                    'message_dispatcher' => 'php_resque_message_dispatcher'
                )
            )
        ));

        $serviceBusManager = new ServiceBusManager($config);

        $jobId = null;

        StaticEventManager::getInstance()->attach(
            'message_dispatcher',
            'dispatch.pre',
            function (EventInterface $e) {
                $e->getTarget()->activateJobTracking();
            }
        );

        StaticEventManager::getInstance()->attach(
            'message_dispatcher',
            'dispatch.post',
            function (EventInterface $e) use (&$jobId) {
                $jobId = $e->getParam('jobId');
            }
        );

        $removeFile = new RemoveFileCommand($this->testFile);

        $serviceBusManager->getCommandBus('php-resque-test-bus')->send($removeFile);

        $this->assertNotNull($jobId);

        $status = new \Resque_Job_Status($jobId);

        $this->assertEquals(\Resque_Job_Status::STATUS_WAITING, $status->get());

        $worker = new \Resque_Worker(array('php-resque-test-queue'));

        $worker->work(0);

        $this->assertEquals(\Resque_Job_Status::STATUS_COMPLETE, $status->get());

        $this->assertFalse(file_exists($this->testFile));
    }
}
 