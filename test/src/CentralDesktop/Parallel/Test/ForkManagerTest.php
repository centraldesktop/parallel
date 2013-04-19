<?php
/**
 * Created by IntelliJ IDEA.
 * User: thyde
 * Date: 4/19/13
 * Time: 2:19 PM
 * To change this template use File | Settings | File Templates.
 */

namespace CentralDesktop\Parallel\Test;


use CentralDesktop\Parallel;
use Monolog\Logger;
use Monolog\Handler;


class ForkManagerTest extends \PHPUnit_Framework_TestCase {

    public
    function testManager() {
        // Make sure PHP checks for signals, and handles them.
        declare(ticks = 1);


        $test_text = "I'm doing something";

        $fm = new Parallel\ForkManager(1);

        $logger = new Logger("test");
        $testH  = new Handler\TestHandler();

        $logger->pushHandler($testH);

        $fm->setLogger($logger);

        $this->markTestIncomplete("Forking is hard to test.  Todo, refactor to make fork a module so we can mock");

        while ($fm->alive()) {
//            if ($fm->start('hello world')) {
//                continue;
//            }

            $logger->info($test_text);

            $fm->shutdown();

//            $fm->stop();

        }


        $has_announce = false;
        foreach ($testH->getRecords() as $record) {
            $message = $record['message'];
            if (preg_match('#Announcing#', $message)) {
                $has_announce = true;
                break;
            }

        }


        $this->assertTrue($has_announce, "FM Never announced a thread");
        $this->assertTrue($testH->hasInfo($test_text), "FM never got to the fork body");
    }
}