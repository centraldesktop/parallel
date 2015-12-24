<?php
/*


Copyright 2008, CentralDesktop Inc

Licensed under the Apache License, Version 2.0 (the "License");
you may not use this file except in compliance with the License.
You may obtain a copy of the License at

http://www.apache.org/licenses/LICENSE-2.0

Unless required by applicable law or agreed to in writing, software
distributed under the License is distributed on an "AS IS" BASIS,
WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied.
See the License for the specific language governing permissions and
limitations under the License.

 */

/**
 * Allows easy control of parallel processing of data via process forking.
 * This utility manages all the forks so you don't have to do it.
 *
 * Directly inspired by Perl's Parallel::ForkManager
 *
 * @author thyde@centraldesktop.com
 */

namespace CentralDesktop\Parallel;

use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

class ForkManager implements LoggerAwareInterface {
    private $active = true;
    private $max_processes = 0;
    private $pids = array();
    private static $have_ticks = false;

    private static $fms = array();

    private $parent_handler = null;
    private $child_handler = null;

    private $logger;

    // Signals that we trap by default
    protected static $trapped_signals = array(
        SIGHUP, SIGINT, SIGABRT, SIGTERM, SIGQUIT
    );

    public
    function __construct($processes = 4, $use_default_sighandler = true) {
        $this->logger        = new NullLogger();
        $this->max_processes = $processes;

        self::$fms[] = $this;

        // Verify that 'declare(ticks=1)' has been called by the caller.
        // We only need this done once.
        if (!self::$have_ticks) {
            $this->setup_ticks();
        }

        if ($use_default_sighandler) {
            $this->set_default_parent_sighandler();
        }
    }

    public
    function alive() {
        return $this->active;
    }

    public static
    function confirmTicks() {
        self::$have_ticks = true;
    }

    private
    function setup_ticks() {
        $tick_f = function () {
            ForkManager::confirmTicks();
        };
        register_tick_function($tick_f);


        // This is a short NOP+microsleep, just to
        // give verify_declare_ticks() a change to verify.
        $i = 0;
        $i++;
        $i++;
        $i++;
        time_nanosleep(0, 5000);


        if (self::$have_ticks) {
            //unregister_tick_function($tick_f);
        }
        else {
            die("FM requires a 'declare(ticks=1);' in the calling code.\n");
        }
    }

    public
    function start($id = "Some Random Process") {
        // If the __construct() test failed, retest now.
        if (!self::$have_ticks) {
            $this->setup_ticks();
        }

        $pid = pcntl_fork();

        if ($pid == -1) {
            die("Fork you!!");
        }
        elseif ($pid) {
            $this->pids[$pid] = $id;

            $this->logger->info("Announcing new process w/ pid ($pid): {$id}");

            if (count(array_keys($this->pids)) >= $this->max_processes) {
                // wait for child to finish so we can start another.

                // PHP doesn't dispatch signals while we're waiting in pcntl_wait().
                // All signals are queued up, and dispatched when it exits.
                // But that doesn't work for ForkManager, because I need to receive
                // the signal to start killing child processes... chicken and egg...
                // Instead, poll pcntl_wait() instead of blocking.  If nothing
                // exitted, and we're still supposed to run, poll
                while ($this->alive()) {
                    /**
                     * @var $status int is a reference to a variable that stores $?
                     * plus some more crap for the child that exitted
                     */
                    $status   = 0;
                    $childpid = pcntl_wait($status, WNOHANG);
                    // If nothing happened, or an error happened, send out signals and delay
                    if ($childpid === 0) {
                        pcntl_signal_dispatch();
                        time_nanosleep(0, 500000000); // Sleep for 0.5s
                    } elseif ($childpid === -1) {
                        // Go ahead and dispatch messages and sleep.  We don't want to busy wait.
                        pcntl_signal_dispatch();
                        time_nanosleep(0, 500000000); // Sleep for 0.5s
                    }
                    else {
                        if (pcntl_wifexited($status)) {

                            $code = pcntl_wexitstatus($status);
                            if ($code != 0) {
                                $msg = "Child exited abnormally with code $code";
                                if (extension_loaded('newrelic')) {
                                    newrelic_notice_error($msg);
                                }
                                $this->logger->critical("Process exited abnormally",
                                                        ['process' => $id, 'code' => $code]);
                            }
                        }

                        // If this is a known child, exit the loop, so we can re-spawn
                        // If the child is not known, don't spawn a new child
                        $this->reap($childpid);
                        if (array_key_exists ($childpid, $this->pids)) {
                            break;
                        }
                    }
                }
            }
        }
        else {
            //child ... 

            // Setup signal handlers, if any
            foreach (self::$trapped_signals as $signo) {
                if (is_null($this->child_handler)) {
                    pcntl_signal($signo, function ($signo) {
                        exit($signo);
                    });
                }
                else {
                    pcntl_signal($signo, $this->child_handler);
                }
            }

            //code should be in the caller
        }

        return $pid;
    }

    /**
     * Shutdows down the current fork
     */
    public
    function stop() {
        exit;
    }


    private
    function reap($pid) {
        unset($this->pids[$pid]);
    }

    /**
     * Waits for all children to finish.
     */
    public
    function finish() {
        $signal = null;
        while (($child = pcntl_wait($signal)) != -1) {
            $this->logger->info("Reaped child $child from $signal");
        }
    }

    /**
     * Shuts down the process and asks all the children to exit as well.
     * Ignores SIGHUP
     *
     * @param int     $sig  POSIX signal you want to shutdown with
     * @param boolean $wait Wait for the processes to exit before returning?
     */
    public
    function shutdown($sig = SIGINT, $wait = false) {
        if ($sig == SIGHUP) {
            return;
        }
        else {
            $this->active = false;
            // ask children to shut down.
            foreach ($this->pids as $pid => $name) {
                posix_kill($pid, $sig);
            }

            if ($wait) {
                $this->finish();
            }

            // Clean up signal handlers, and their side effects.
            $this->unset_parent_sighandler();
        }
    }

    /**
     * Shuts down ALL ForkManager instances and asks all the children to exit as well.
     *
     * @param int $sig POSIX signal you want to shutdown with
     */
    public static
    function shutdown_all($sig = SIGINT) {
        foreach (self::$fms as $fm) {
            $fm->shutdown($sig);
        }
    }

    /*
     * This sets the signal handler that's installed in the parent process
     * By default, ForkManager installs a handler that will rebroadcast the signal to all children.
     *
     * $function is set to pcntl_signal().  As such, it can be one of 3 forms:
     * 'some_function_name'      -- some_function_name() should access $signo param
     * array( $obj, 'some_method_name');      -- some_method_name() should access $signo param
     * function($signo) {}       -- Anonymous function
     */
    public
    function set_parent_sighandler($function) {
        $this->parent_handler = $function;

        foreach (self::$trapped_signals as $signo) {
            pcntl_signal($signo, $function);
        }
    }

    /*
     * Returns the signal handlers to the standard CD_ForkManager handler.
     */
    public
    function set_default_parent_sighandler() {
        $this->parent_handler = null;

        foreach (self::$trapped_signals as $signo) {
            pcntl_signal($signo, array($this, 'shutdown'));
        }
    }

    /*
     * Internal function called by ->shutdown().
     * This is needed to decrement the reference count on $this
     */
    private
    function unset_parent_sighandler() {
        $this->parent_handler = null;

        foreach (self::$trapped_signals as $signo) {
            pcntl_signal($signo, function ($signo) {
                exit($signo);
            });
        }
    }


    /*
     * This sets the signal handler that's installed in the child process, right after fork.
     * By default, ForkManager does not install a handler in child processes.
     *
     * $function is set to pcntl_signal().  As such, it can be one of 3 forms:
     * 'some_function_name'      -- some_function_name() should access $signo param
     * array( $obj, 'some_method_name');      -- some_method_name() should access $signo param
     * function($signo) {}       -- Anonymous function
     */
    public
    function set_child_sighandler($function) {
        $this->child_handler = $function;
    }


    /**
     * Sets a logger instance on the object
     *
     * @param LoggerInterface $logger
     *
     * @return null
     */
    public
    function setLogger(LoggerInterface $logger) {
        $this->logger = $logger;
    }
}

