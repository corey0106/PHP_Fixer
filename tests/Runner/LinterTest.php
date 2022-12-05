<?php

declare(strict_types=1);

namespace TwigCsFixer\Tests\Runner;

use SplFileInfo;
use Twig\Environment;
use Twig\Error\SyntaxError;
use TwigCsFixer\Cache\Manager\CacheManagerInterface;
use TwigCsFixer\Environment\StubbedEnvironment;
use TwigCsFixer\Exception\CannotFixFileException;
use TwigCsFixer\Exception\CannotTokenizeException;
use TwigCsFixer\Report\SniffViolation;
use TwigCsFixer\Ruleset\Ruleset;
use TwigCsFixer\Runner\FixerInterface;
use TwigCsFixer\Runner\Linter;
use TwigCsFixer\Standard\Generic;
use TwigCsFixer\Tests\FileTestCase;
use TwigCsFixer\Token\Tokenizer;
use TwigCsFixer\Token\TokenizerInterface;

final class LinterTest extends FileTestCase
{
    public function testUnreadableFilesAreReported(): void
    {
        $fileNotReadablePath = $this->getTmpPath(__DIR__.'/Fixtures/Linter/file_not_readable.twig');
        if ($this->getFilesystem()->exists($fileNotReadablePath)) {
            $this->getFilesystem()->remove($fileNotReadablePath);
        }

        $filePath = $this->getTmpPath(__DIR__.'/Fixtures/Linter/file.twig');

        $env = new StubbedEnvironment();
        $tokenizer = new Tokenizer($env);
        $ruleset = new Ruleset();

        $cacheManager = $this->createMock(CacheManagerInterface::class);
        $cacheManager->method('needFixing')->willReturn(true);
        // Ensure the second file is fixed and cached
        $cacheManager->expects(static::once())->method('setFile')->with($filePath);

        $linter = new Linter($env, $tokenizer, $cacheManager);
        $report = $linter->run(
            [new SplFileInfo($fileNotReadablePath), new SplFileInfo($filePath)],
            $ruleset,
        );

        $messages = $report->getMessages($fileNotReadablePath);
        static::assertCount(1, $messages);

        $message = $messages[0];
        static::assertSame('Unable to read file.', $message->getMessage());
        static::assertSame(SniffViolation::LEVEL_FATAL, $message->getLevel());
        static::assertSame($fileNotReadablePath, $message->getFilename());

        static::assertCount(0, $report->getMessages($filePath));
    }

    public function testInvalidFilesAreReported(): void
    {
        $filePath = $this->getTmpPath(__DIR__.'/Fixtures/Linter/file.twig');

        $env = $this->createStub(Environment::class);
        $env->method('tokenize')->willThrowException(new SyntaxError('Error.'));
        $tokenizer = $this->createStub(TokenizerInterface::class);
        $ruleset = new Ruleset();

        $linter = new Linter($env, $tokenizer);
        $report = $linter->run([new SplFileInfo($filePath)], $ruleset);

        $messages = $report->getMessages($filePath);
        static::assertCount(1, $messages);

        $message = $messages[0];
        static::assertSame('File is invalid: Error.', $message->getMessage());
        static::assertSame(SniffViolation::LEVEL_FATAL, $message->getLevel());
        static::assertSame($filePath, $message->getFilename());
    }

    public function testUntokenizableFilesAreReported(): void
    {
        $filePath = $this->getTmpPath(__DIR__.'/Fixtures/Linter/file.twig');
        $filePath2 = $this->getTmpPath(__DIR__.'/Fixtures/Linter/file2.twig');

        $env = new StubbedEnvironment();
        $tokenizer = $this->createStub(TokenizerInterface::class);

        $call = 0;
        $tokenizer->method('tokenize')->willReturnCallback(
            static function () use (&$call): array {
                if (0 === $call) {
                    $call++;
                    throw CannotTokenizeException::unknownError();
                }

                return [];
            }
        );
        $ruleset = new Ruleset();

        $cacheManager = $this->createMock(CacheManagerInterface::class);
        $cacheManager->method('needFixing')->willReturn(true);
        // Ensure the second file is fixed and cached
        $cacheManager->expects(static::once())->method('setFile')->with($filePath2);

        $linter = new Linter($env, $tokenizer, $cacheManager);
        $report = $linter->run(
            [new SplFileInfo($filePath), new SplFileInfo($filePath2)],
            $ruleset
        );

        $messages = $report->getMessages($filePath);
        static::assertCount(1, $messages);

        $message = $messages[0];
        static::assertSame('Unable to tokenize file: The template is invalid.', $message->getMessage());
        static::assertSame(SniffViolation::LEVEL_FATAL, $message->getLevel());
        static::assertSame($filePath, $message->getFilename());
    }

    public function testUserDeprecationAreReported(): void
    {
        $filePath = $this->getTmpPath(__DIR__.'/Fixtures/Linter/file.twig');

        $env = new StubbedEnvironment();
        $tokenizer = $this->createStub(TokenizerInterface::class);
        $tokenizer->method('tokenize')->willReturnCallback(static function (): array {
            @trigger_error('Default');
            @trigger_error('User Deprecation', \E_USER_DEPRECATED);

            return [];
        });
        $ruleset = new Ruleset();

        $linter = new Linter($env, $tokenizer);
        $report = $linter->run([new SplFileInfo($filePath)], $ruleset);

        // Ensure the error handler is restored.
        @trigger_error('User Deprecation 2', \E_USER_DEPRECATED);

        $messages = $report->getMessages($filePath);
        static::assertCount(1, $messages);

        $message = $messages[0];
        static::assertSame('User Deprecation', $message->getMessage());
        static::assertSame(SniffViolation::LEVEL_NOTICE, $message->getLevel());
        static::assertSame($filePath, $message->getFilename());
    }

    public function testFileIsModifiedWhenFixed(): void
    {
        $filePath = $this->getTmpPath(__DIR__.'/Fixtures/Linter/file.twig');

        $env = new StubbedEnvironment();
        $tokenizer = new Tokenizer($env);
        $ruleset = new Ruleset();

        $fixer = $this->createMock(FixerInterface::class);
        $fixer->expects(static::once())->method('fixFile')->willReturn('newContent');

        $linter = new Linter($env, $tokenizer);
        $linter->run([new SplFileInfo($filePath)], $ruleset, $fixer);

        static::assertStringEqualsFile($filePath, 'newContent');
    }

    /**
     * @dataProvider buggyFixesAreReportedDataProvider
     */
    public function testBuggyFixesAreReported(
        CannotFixFileException|CannotTokenizeException $exception
    ): void {
        $filePath = $this->getTmpPath(__DIR__.'/Fixtures/Linter/file.twig');

        $env = new StubbedEnvironment();
        $tokenizer = new Tokenizer($env);
        $ruleset = new Ruleset();

        $fixer = $this->createStub(FixerInterface::class);
        $fixer->method('fixFile')->willThrowException($exception);

        $linter = new Linter($env, $tokenizer);
        $report = $linter->run([new SplFileInfo($filePath)], $ruleset, $fixer);

        $messages = $report->getMessages($filePath);
        static::assertNotCount(0, $messages);

        $message = $messages[0];
        static::assertStringContainsString('Unable to fix file', $message->getMessage());
        static::assertSame(SniffViolation::LEVEL_FATAL, $message->getLevel());
        static::assertSame($filePath, $message->getFilename());
    }

    /**
     * @return iterable<array{CannotFixFileException|CannotTokenizeException}>
     */
    public function buggyFixesAreReportedDataProvider(): iterable
    {
        yield [CannotFixFileException::infiniteLoop()];
        yield [CannotTokenizeException::unknownError()];
    }

    public function testFileIsSkippedIfCached(): void
    {
        $env = new StubbedEnvironment();
        $ruleset = new Ruleset();

        $tokenizer = $this->createMock(TokenizerInterface::class);
        $tokenizer->expects(static::never())->method('tokenize');

        $cacheManager = $this->createMock(CacheManagerInterface::class);
        $cacheManager->method('needFixing')->willReturn(false);
        $cacheManager->expects(static::never())->method('setFile');

        $fixer = $this->createMock(FixerInterface::class);
        $fixer->expects(static::never())->method('fixFile');

        $linter = new Linter($env, $tokenizer, $cacheManager);
        $linter->run([new SplFileInfo(__DIR__.'/Fixtures/Linter/file.twig')], $ruleset, $fixer);
    }

    public function testFileIsNotSkippedIfNotCached(): void
    {
        $filePath = $this->getTmpPath(__DIR__.'/Fixtures/Linter/file.twig');

        $env = new StubbedEnvironment();
        $tokenizer = new Tokenizer($env);
        $ruleset = new Ruleset();

        $cacheManager = $this->createMock(CacheManagerInterface::class);
        $cacheManager->method('needFixing')->willReturn(true);
        $cacheManager->expects(static::once())->method('setFile');

        $fixer = $this->createMock(FixerInterface::class);
        $fixer->expects(static::once())->method('fixFile');

        $linter = new Linter($env, $tokenizer, $cacheManager);
        $linter->run([new SplFileInfo($filePath)], $ruleset, $fixer);
    }

    public function testFileIsNotCachedWhenReportHasErrors(): void
    {
        $filePath = $this->getTmpPath(__DIR__.'/Fixtures/Linter/file.twig');

        $env = new StubbedEnvironment();
        $tokenizer = new Tokenizer($env);
        $ruleset = new Ruleset();
        $ruleset->addStandard(new Generic());

        $cacheManager = $this->createMock(CacheManagerInterface::class);
        $cacheManager->method('needFixing')->willReturn(true);
        $cacheManager->expects(static::never())->method('setFile');

        $linter = new Linter($env, $tokenizer, $cacheManager);
        $linter->run([new SplFileInfo($filePath)], $ruleset);
    }
}
