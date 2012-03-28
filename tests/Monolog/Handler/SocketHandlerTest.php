<?php

/*
 * This file is part of the Monolog package.
 *
 * (c) Jordi Boggiano <j.boggiano@seld.be>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Monolog\Handler;

use Monolog\TestCase;
use Monolog\Logger;

/**
 * @author Pablo de Leon Belloc <pablolb@gmail.com>
 */
class SocketHandlerTest extends TestCase
{

    /**
     * @var Monolog\Handler\SocketHandler
     */
    private $handler;

    /**
     * @var resource
     */
    private $res;

    /**
     * @expectedException UnexpectedValueException
     */
    public function testInvalidHostname()
    {
        $this->createHandler('garbage://here');
        $this->writeRecord('data');
    }

    /**
     * @expectedException \InvalidArgumentException
     */
    public function testBadConnectionTimeout()
    {
        $this->createHandler('localhost:1234');
        $this->handler->setConnectionTimeout(-1);
    }

    public function testSetConnectionTimeout()
    {
        $this->createHandler('localhost:1234');
        $this->handler->setConnectionTimeout(10);
        $this->assertEquals(10, $this->handler->getConnectionTimeout());
    }

    /**
     * @expectedException \InvalidArgumentException
     */
    public function testBadTimeout()
    {
        $this->createHandler('localhost:1234');
        $this->handler->setTimeout(-1);
    }

    public function testSetTimeout()
    {
        $this->createHandler('localhost:1234');
        $this->handler->setTimeout(10);
        $this->assertEquals(10, $this->handler->getTimeout());
    }

    public function testSetConnectionString()
    {
        $this->createHandler('tcp://localhost:9090');
        $this->assertEquals('tcp://localhost:9090', $this->handler->getConnectionString());
    }

    public function testConnectionRefuesed()
    {
        try {
            $this->createHandler('127.0.0.1:7894');
            $string = 'Hello world';
            $this->writeRecord($string);
            $this->fail("Shoul not connect - are you running a server on 127.0.0.1:7894 ?");
        } catch (\UnexpectedValueException $e) {
            
        }
    }

    /**
     * @expectedException UnexpectedValueException
     */
    public function testExceptionIsThrownOnFsockopenError()
    {
        $this->setMockHandler(array('fsockopen'));
        $this->handler->expects($this->once())
                ->method('fsockopen')
                ->will($this->returnValue(false));
        $this->writeRecord('Hello world');
    }

    /**
     * @expectedException UnexpectedValueException
     */
    public function testExceptionIsThrownOnPfsockopenError()
    {
        $this->setMockHandler(array('pfsockopen'));
        $this->handler->expects($this->once())
                ->method('pfsockopen')
                ->will($this->returnValue(false));
        $this->handler->setPersistent(true);
        $this->writeRecord('Hello world');
    }

    /**
     * @expectedException UnexpectedValueException
     */
    public function testExceptionIsThrownIfCannotSetTimeout()
    {
        $this->setMockHandler(array('stream_set_timeout'));
        $this->handler->expects($this->once())
                ->method('stream_set_timeout')
                ->will($this->returnValue(false));
        $this->writeRecord('Hello world');
    }

    /**
     * @expectedException RuntimeException
     */
    public function testWriteFailsOnIfFwriteReturnsFalse()
    {
        $this->setMockHandler(array('fwrite'));

        $map = array(
            array('Hello world', 6),
            array('world', false),
        );

        $this->handler->expects($this->exactly(2))
                ->method('fwrite')
                ->will($this->returnValueMap($map));

        $this->writeRecord('Hello world');
    }

    /**
     * @expectedException RuntimeException
     */
    public function testWriteFailsIfStreamTimesOut()
    {
        $this->setMockHandler(array('fwrite', 'stream_get_meta_data'));

        $map = array(
            array('Hello world', 6),
            array('world', 5),
        );

        $this->handler->expects($this->exactly(1))
                ->method('fwrite')
                ->will($this->returnValueMap($map));
        $this->handler->expects($this->exactly(1))
                ->method('stream_get_meta_data')
                ->will($this->returnValue(array('timed_out' => true)));


        $this->writeRecord('Hello world');
    }

    /**
     * @expectedException RuntimeException
     */
    public function testWriteFailsOnIncompleteWrite()
    {
        $this->setMockHandler(array('fwrite', 'stream_get_meta_data'));

        $res = $this->res;
        $callback = function($string) use ($res) {
                    fclose($res);
                    return strlen('Hello');
                };

        $this->handler->expects($this->exactly(1))
                ->method('fwrite')
                ->will($this->returnCallback($callback));
        $this->handler->expects($this->exactly(1))
                ->method('stream_get_meta_data')
                ->will($this->returnValue(array('timed_out' => false)));

        $this->writeRecord('Hello world');
    }

    public function testWriteWithMemoryFile()
    {
        $this->setMockHandler();
        $this->writeRecord('test1');
        $this->writeRecord('test2');
        $this->writeRecord('test3');
        fseek($this->res, 0);
        $this->assertEquals('test1test2test3', fread($this->res, 1024));
    }

    public function testWriteWithMock()
    {
        $this->setMockHandler(array('fwrite'));

        $map = array(
            array('Hello world', 6),
            array('world', 5),
        );

        $this->handler->expects($this->exactly(2))
                ->method('fwrite')
                ->will($this->returnValueMap($map));

        $this->writeRecord('Hello world');
    }

    public function testClose()
    {
        $this->setMockHandler();
        $this->writeRecord('Hello world');
        $this->assertTrue(is_resource($this->res));
        $this->handler->close();
        $this->assertFalse(is_resource($this->res));
    }

    public function testCloseDoesNotClosePersistentSocket()
    {
        $this->setMockHandler();
        $this->handler->setPersistent(true);
        $this->writeRecord('Hello world');
        $this->assertTrue(is_resource($this->res));
        $this->handler->close();
        $this->assertTrue(is_resource($this->res));
    }

    private function createHandler($connectionString)
    {
        $this->handler = new SocketHandler($connectionString);
        $this->handler->setFormatter($this->getIdentityFormatter());
    }

    private function writeRecord($string)
    {
        $this->handler->handle($this->getRecord(Logger::WARNING, $string));
    }

    private function setMockHandler(array $methods = array())
    {
        $this->res = fopen('php://memory', 'a');

        $defaultMethods = array('fsockopen', 'pfsockopen', 'stream_set_timeout');
        $newMethods = array_diff($methods, $defaultMethods);

        $finalMethods = array_merge($defaultMethods, $newMethods);

        $this->handler = $this->getMock(
                '\Monolog\Handler\SocketHandler', $finalMethods, array('localhost:1234')
        );

        if (!in_array('fsockopen', $methods)) {
            $this->handler->expects($this->any())
                    ->method('fsockopen')
                    ->will($this->returnValue($this->res));
        }

        if (!in_array('pfsockopen', $methods)) {
            $this->handler->expects($this->any())
                    ->method('pfsockopen')
                    ->will($this->returnValue($this->res));
        }

        if (!in_array('stream_set_timeout', $methods)) {
            $this->handler->expects($this->any())
                    ->method('stream_set_timeout')
                    ->will($this->returnValue(true));
        }

        $this->handler->setFormatter($this->getIdentityFormatter());
    }
    
}
