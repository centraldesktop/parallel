An easy to use and simple library for doing parallel processing using simple process forks.

All credit should go to the amazing maintainers of Perl's (CPAN) Parallel::ForkManager which I used for about 10 years.


To use:
=======

Add this package to your composer dependencies.

use CentralDesktop\Paralell\ForkManager;


// build an object, limit to total concurrent processes.

*You must declare(ticks = 1); in order for signal handing to work properly in PHP*


> $fm = new ForkManager(10);
> $fm->start();
>
> while ($fm->alive()) {
>   if ($fm->start()) {
>     continue;
>   }
>
>   do_something();
>
>   $fm->stop();
> }
> $fm->shutdown_all();


You probably may want to handle specific signals by installing signal handlers.


> $fm->set_parent_sighandler(array($this, 'parent_signal_handler'));

> $fm->set_child_sighandler(array($this, 'child_signal_handler'));
