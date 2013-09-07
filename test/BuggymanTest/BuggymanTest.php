<?php
/**
 * @author Evgeny Shpilevsky <evgeny@shpilevsky.com>
 */

namespace BuggymanTest;


use Buggyman\Buggyman;

class BuggymanTest extends \PHPUnit_Framework_TestCase
{

    public function testExceptionToArray()
    {
        Buggyman::setRoot(__DIR__);
        $exception = new \Exception('message', 432, new \RuntimeException('subexception'));
        $array = Buggyman::exceptionToArray($exception);
        $this->assertEquals('message', $array['message']);
        $this->assertEquals('Exception', $array['exception']);
        $this->assertEquals('subexception', $array['previous']['message']);
        $this->assertEquals('RuntimeException', $array['previous']['exception']);
        $this->assertEquals(432, $array['code']);
        $this->assertEquals(17, $array['line']);
        $this->assertTrue(strlen($array['file']) > 0);
        $this->assertTrue(strlen($array['stack']) > 0);
        $this->assertEquals(__DIR__, $array['root']);
    }


    public function testSendReport()
    {
        $report = Buggyman::exceptionToArray(new \RuntimeException('Test exception'));
        $report['meta'] = array(
            'test' => 'hi!'
        );
        $report['root'] = dirname(__DIR__);

        Buggyman::setToken('2aepGEj3Jt6YhkKKzvNdgtXW8o7cDAnkZwWNO6kl');
        $result = Buggyman::sendReport(array($report));
        $this->assertEquals('{"status":"1"}', $result);
    }


}
 