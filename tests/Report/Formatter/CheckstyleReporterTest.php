<?php

declare(strict_types=1);

namespace TwigCsFixer\Tests\Report\Formatter;

use PHPUnit\Framework\TestCase;
use SplFileInfo;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Output\OutputInterface;
use TwigCsFixer\Report\Report;
use TwigCsFixer\Report\Reporter\CheckstyleReporter;
use TwigCsFixer\Report\SniffViolation;

final class CheckstyleReporterTest extends TestCase
{
    /**
     * @dataProvider displayDataProvider
     */
    public function testDisplayErrors(string $expected, ?string $level): void
    {
        $textFormatter = new CheckstyleReporter();

        $file = __DIR__.'/Fixtures/file.twig';
        $file2 = __DIR__.'/Fixtures/file2.twig';
        $file3 = __DIR__.'/Fixtures/file3.twig';
        $report = new Report([new SplFileInfo($file), new SplFileInfo($file2), new SplFileInfo($file3)]);

        $violation0 = new SniffViolation(SniffViolation::LEVEL_NOTICE, 'Notice', $file, 1, 11, 'NoticeSniff');
        $report->addMessage($violation0);
        $violation1 = new SniffViolation(SniffViolation::LEVEL_WARNING, 'Warning', $file, 2, 22, 'WarningSniff');
        $report->addMessage($violation1);
        $violation2 = new SniffViolation(SniffViolation::LEVEL_ERROR, 'Error', $file, 3, 33, 'ErrorSniff');
        $report->addMessage($violation2);
        $violation3 = new SniffViolation(SniffViolation::LEVEL_FATAL, 'Fatal', $file);
        $report->addMessage($violation3);

        $violation4 = new SniffViolation(SniffViolation::LEVEL_NOTICE, 'Notice2', $file2, 1, 11, 'Notice2Sniff');
        $report->addMessage($violation4);

        $violation5 = new SniffViolation(SniffViolation::LEVEL_FATAL, '\'"<&>"\'', $file3);
        $report->addMessage($violation5);

        $output = new BufferedOutput(OutputInterface::VERBOSITY_NORMAL, true);
        $textFormatter->display($output, $report, $level);

        $text = $output->fetch();
        static::assertStringContainsString($expected, $text);
    }

    /**
     * @return iterable<array-key, array{string, string|null}>
     */
    public static function displayDataProvider(): iterable
    {
        yield [
            sprintf(
                <<<EOD
                    <?xml version="1.0" encoding="UTF-8"?>
                    <checkstyle>
                      <file name="%s/Fixtures/file.twig">
                        <error line="1" column="11" severity="notice" message="Notice" source="NoticeSniff"/>
                        <error line="2" column="22" severity="warning" message="Warning" source="WarningSniff"/>
                        <error line="3" column="33" severity="error" message="Error" source="ErrorSniff"/>
                        <error severity="fatal" message="Fatal"/>
                      </file>
                      <file name="%s/Fixtures/file2.twig">
                        <error line="1" column="11" severity="notice" message="Notice2" source="Notice2Sniff"/>
                      </file>
                      <file name="%s/Fixtures/file3.twig">
                        <error severity="fatal" message="&apos;&quot;&lt;&amp;&gt;&quot;&apos;"/>
                      </file>
                    </checkstyle>
                    EOD,
                __DIR__,
                __DIR__,
                __DIR__
            ),
            null,
        ];

        yield [
            sprintf(
                <<<EOD
                    <?xml version="1.0" encoding="UTF-8"?>
                    <checkstyle>
                      <file name="%s/Fixtures/file.twig">
                        <error line="3" column="33" severity="error" message="Error" source="ErrorSniff"/>
                        <error severity="fatal" message="Fatal"/>
                      </file>
                      <file name="%s/Fixtures/file3.twig">
                        <error severity="fatal" message="&apos;&quot;&lt;&amp;&gt;&quot;&apos;"/>
                      </file>
                    </checkstyle>
                    EOD,
                __DIR__,
                __DIR__
            ),
            Report::MESSAGE_TYPE_ERROR,
        ];
    }
}
