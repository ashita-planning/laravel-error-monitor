<?php

declare(strict_types=1);

namespace Apkk\LaravelErrorMonitor\Tests\Unit;

use Apkk\LaravelErrorMonitor\Support\HttpStatusResolver;
use PHPUnit\Framework\TestCase;

final class HttpStatusResolverTest extends TestCase
{
    public function test_it_maps_a_client_error_logged_at_error_level(): void
    {
        $resolver = new HttpStatusResolver;

        $this->assertSame(404, $resolver->resolve('Symfony\Component\HttpKernel\Exception\NotFoundHttpException'));
        $this->assertSame(422, $resolver->resolve('Illuminate\Validation\ValidationException'));
        $this->assertSame(419, $resolver->resolve('Illuminate\Session\TokenMismatchException'));
    }

    public function test_it_matches_on_the_short_class_name(): void
    {
        $resolver = new HttpStatusResolver;

        $this->assertSame(404, $resolver->resolve('ModelNotFoundException'));
        $this->assertSame(404, $resolver->resolve('\App\Exceptions\ModelNotFoundException'));
    }

    public function test_an_unmapped_throwable_is_only_assumed_to_be_a_server_error(): void
    {
        $resolver = new HttpStatusResolver;

        $this->assertSame(500, $resolver->resolve('RuntimeException'));
        $this->assertNull($resolver->mapped('RuntimeException'), 'An unmapped class must not claim a known status.');
    }

    public function test_it_reports_nothing_without_a_class(): void
    {
        $resolver = new HttpStatusResolver;

        $this->assertNull($resolver->mapped(null));
        $this->assertNull($resolver->mapped(''));
        $this->assertSame(500, $resolver->resolve(null));
    }
}
