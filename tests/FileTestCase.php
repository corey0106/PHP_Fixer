<?php

declare(strict_types=1);

namespace TwigCsFixer\Tests;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Symfony\Component\Filesystem\Filesystem;
use Webmozart\Assert\Assert;

abstract class FileTestCase extends TestCase
{
    private ?Filesystem $filesystem = null;

    private string $cwd;

    private ?string $tmp = null;

    private ?string $dir = null;

    protected function setUp(): void
    {
        parent::setUp();

        $fixtureDir = $this->getDir().'/Fixtures';
        $tmpFixtures = $this->getTmpPath($fixtureDir);

        if ($tmpFixtures !== $fixtureDir) {
            $this->getFilesystem()->remove($tmpFixtures);

            if ($this->getFilesystem()->exists($fixtureDir)) {
                $this->getFilesystem()->mirror($fixtureDir, $tmpFixtures);
            }
        }

        $cwd = getcwd();
        Assert::notFalse($cwd);

        $this->cwd = $cwd;
        chdir($this->getTmpPath($this->getDir()));
    }

    protected function tearDown(): void
    {
        parent::tearDown();

        chdir($this->cwd);
    }

    protected function getFilesystem(): Filesystem
    {
        if (null === $this->filesystem) {
            $this->filesystem = new Filesystem();
        }

        return $this->filesystem;
    }

    protected function getTmpPath(string $path): string
    {
        if (!str_starts_with($path, $this->getDir())) {
            throw new InvalidArgumentException(sprintf('The path "%s" is not supported', $path));
        }

        return str_replace($this->getDir(), $this->getTmp(), $path);
    }

    private function getDir(): string
    {
        if (null === $this->dir) {
            $reflectionClass = new ReflectionClass($this);
            $fileName = $reflectionClass->getFileName();
            Assert::notFalse($fileName);

            $this->dir = \dirname($fileName);
        }

        return $this->dir;
    }

    private function getTmp(): string
    {
        if (null === $this->tmp) {
            $tmp = realpath(sys_get_temp_dir());

            // On GitHub actions we cannot access the tmp dir
            if (false === $tmp) {
                $this->tmp = $this->getDir();
            } else {
                $this->tmp = $tmp.'/twig-cs-fixer';
            }
        }

        return $this->tmp;
    }
}
