<?php

declare(strict_types=1);

namespace Apkk\LaravelErrorMonitor\Tests\Unit;

use Apkk\LaravelErrorMonitor\Support\ApplicationFrameDetector;
use PHPUnit\Framework\TestCase;

final class ApplicationFrameDetectorTest extends TestCase
{
    public function test_it_recognises_application_paths(): void
    {
        $detector = $this->detector();

        $this->assertTrue($detector->isApplication('/srv/app/app/Http/Controllers/OrderController.php'));
        $this->assertTrue($detector->isApplication('/srv/app/routes/web.php'));
    }

    public function test_a_vendor_path_wins_over_an_application_path(): void
    {
        // The deployment root itself contains `app/`, so the vendor fragment
        // has to be decisive or every framework frame would count as ours.
        $this->assertFalse($this->detector()->isApplication('/srv/app/vendor/laravel/framework/src/Illuminate/Routing/Router.php'));
    }

    public function test_a_path_matching_no_fragment_is_not_application_code(): void
    {
        $this->assertFalse($this->detector()->isApplication('/usr/share/php/Psr/Log/LoggerTrait.php'));
    }

    public function test_matching_is_fragment_based_and_therefore_inclusive(): void
    {
        // A deployment root called `/srv/app` contains the `app/` fragment, so
        // its whole tree reads as application code. That errs towards "ours",
        // which is the right direction: a vendor fragment still wins, and the
        // fingerprint would otherwise drop the frames the team can act on.
        $this->assertTrue($this->detector()->isApplication('/srv/app/bootstrap/cache/config.php'));
    }

    public function test_it_normalises_windows_separators(): void
    {
        $this->assertTrue($this->detector()->isApplication('C:\\srv\\app\\app\\Services\\OrderService.php'));
    }

    public function test_an_empty_configuration_accepts_every_non_vendor_frame(): void
    {
        $detector = new ApplicationFrameDetector(applicationPaths: [], vendorPaths: ['vendor/']);

        $this->assertTrue($detector->isApplication('/srv/app/bootstrap/app.php'));
        $this->assertFalse($detector->isApplication('/srv/app/vendor/monolog/Logger.php'));
    }

    public function test_it_rejects_a_frame_without_a_file(): void
    {
        $this->assertFalse($this->detector()->isApplication(null));
        $this->assertFalse($this->detector()->isApplication(''));
    }

    private function detector(): ApplicationFrameDetector
    {
        return new ApplicationFrameDetector(
            applicationPaths: ['app/', 'routes/'],
            vendorPaths: ['vendor/', 'node_modules/'],
        );
    }
}
