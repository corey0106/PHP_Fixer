<?php

declare(strict_types=1);

namespace TwigCsFixer\Runner;

use Twig\Source;
use TwigCsFixer\Exception\CannotFixFileException;
use TwigCsFixer\Ruleset\Ruleset;
use TwigCsFixer\Token\Token;
use TwigCsFixer\Token\TokenizerInterface;
use Webmozart\Assert\Assert;

/**
 * Fixer will fix twig files against a set of rules.
 */
final class Fixer implements FixerInterface
{
    public const MAX_FIXER_ITERATION = 50;

    private int $loops = 0;

    private string $eolChar = "\n";

    /**
     * The list of tokens that make up the file contents.
     *
     * This is a simplified list which just contains the token content and nothing else.
     * This is the array that is updated as fixes are made, not the file's token array.
     * Imploding this array will give you the file content back.
     *
     * @var array<int, string>
     */
    private array $tokens = [];

    /**
     * A list of tokens that have already been fixed.
     *
     * We don't allow the same token to be fixed more than once each time through a file
     * as this can easily cause conflicts between sniffs.
     *
     * @var array<int, string>
     */
    private array $fixedTokens = [];

    /**
     * The last value of each fixed token.
     *
     * If a token is being "fixed" back to its last value, the fix is probably conflicting with another.
     *
     * @var array<int, array{curr: string, prev: string, loop: int}>
     */
    private array $oldTokenValues = [];

    /**
     * A list of tokens that have been fixed during a changeset.
     *
     * All changes in changeset must be able to be applied, or else the entire changeset is rejected.
     *
     * @var array<int, string>
     */
    private array $changeset = [];

    /**
     * Is there an open changeset.
     */
    private bool $inChangeset = false;

    /**
     * Is the current fixing loop in conflict?
     */
    private bool $inConflict = false;

    /**
     * The number of fixes that have been performed.
     */
    private int $numFixes = 0;

    public function __construct(private TokenizerInterface $tokenizer)
    {
    }

    public function fixFile(string $content, Ruleset $ruleset): string
    {
        $this->loops = 0;
        do {
            $this->inConflict = false;

            $twigSource = new Source($content, 'TwigCsFixer');
            $stream = $this->tokenizer->tokenize($twigSource);

            $this->startFile($stream);

            $sniffs = $ruleset->getSniffs();
            foreach ($sniffs as $sniff) {
                $sniff->fixFile($stream, $this);
            }

            $this->loops++;
            $content = $this->getContent();
        } while (
            (0 !== $this->numFixes || $this->inConflict)
            && $this->loops < self::MAX_FIXER_ITERATION
        );

        if ($this->numFixes > 0) {
            throw CannotFixFileException::infiniteLoop();
        }

        return $content;
    }

    /**
     * Start recording actions for a changeset.
     */
    public function beginChangeset(): void
    {
        if ($this->inConflict) {
            return;
        }

        $this->changeset = [];
        $this->inChangeset = true;
    }

    /**
     * Stop recording actions for a changeset, and apply logged changes.
     */
    public function endChangeset(): void
    {
        if ($this->inConflict) {
            return;
        }

        $this->inChangeset = false;

        $applied = [];
        foreach ($this->changeset as $tokenPosition => $content) {
            $success = $this->replaceToken($tokenPosition, $content);
            if (!$success) {
                // Rolling back all changes.
                foreach ($applied as $appliedTokenPosition) {
                    $this->revertToken($appliedTokenPosition);
                }
                break;
            }

            $applied[] = $tokenPosition;
        }

        $this->changeset = [];
    }

    public function replaceToken(int $tokenPosition, string $content): bool
    {
        if ($this->inConflict) {
            return false;
        }

        if (!$this->inChangeset && isset($this->fixedTokens[$tokenPosition])) {
            return false;
        }

        if ($this->inChangeset) {
            $this->changeset[$tokenPosition] = $content;

            return true;
        }

        if (!isset($this->oldTokenValues[$tokenPosition])) {
            $this->oldTokenValues[$tokenPosition] = [
                'prev' => $this->tokens[$tokenPosition],
                'curr' => $content,
                'loop' => $this->loops,
            ];
        } elseif (
            $content === $this->oldTokenValues[$tokenPosition]['prev']
            && ($this->loops - 1) === $this->oldTokenValues[$tokenPosition]['loop']
        ) {
            $this->inConflict = true;

            return false;
        } else {
            $this->oldTokenValues[$tokenPosition]['prev'] = $this->oldTokenValues[$tokenPosition]['curr'];
            $this->oldTokenValues[$tokenPosition]['curr'] = $content;
            $this->oldTokenValues[$tokenPosition]['loop'] = $this->loops;
        }

        $this->fixedTokens[$tokenPosition] = $this->tokens[$tokenPosition];
        $this->tokens[$tokenPosition] = $content;
        $this->numFixes++;

        return true;
    }

    public function addNewline(int $tokenPosition): bool
    {
        $current = $this->getTokenContent($tokenPosition);

        return $this->replaceToken($tokenPosition, $current.$this->eolChar);
    }

    public function addNewlineBefore(int $tokenPosition): bool
    {
        $current = $this->getTokenContent($tokenPosition);

        return $this->replaceToken($tokenPosition, $this->eolChar.$current);
    }

    public function addContent(int $tokenPosition, string $content): bool
    {
        $current = $this->getTokenContent($tokenPosition);

        return $this->replaceToken($tokenPosition, $current.$content);
    }

    public function addContentBefore(int $tokenPosition, string $content): bool
    {
        $current = $this->getTokenContent($tokenPosition);

        return $this->replaceToken($tokenPosition, $content.$current);
    }

    /**
     * @param list<Token> $tokens
     */
    private function startFile(array $tokens): void
    {
        $this->numFixes = 0;
        $this->fixedTokens = [];

        $this->tokens = array_map(static fn (Token $token): string => $token->getValue(), $tokens);

        preg_match("/\r\n?|\n/", $this->getContent(), $matches);
        $this->eolChar = $matches[0] ?? "\n";
    }

    private function getContent(): string
    {
        return implode('', $this->tokens);
    }

    /**
     * This function takes changesets into account so should be used
     * instead of directly accessing the token array.
     */
    private function getTokenContent(int $tokenPosition): string
    {
        if ($this->inChangeset && isset($this->changeset[$tokenPosition])) {
            return $this->changeset[$tokenPosition];
        }

        return $this->tokens[$tokenPosition];
    }

    private function revertToken(int $tokenPosition): void
    {
        $errorMessage = sprintf('Nothing to revert at position %s', $tokenPosition);
        Assert::keyExists($this->fixedTokens, $tokenPosition, $errorMessage);

        $this->tokens[$tokenPosition] = $this->fixedTokens[$tokenPosition];
        unset($this->fixedTokens[$tokenPosition]);
        $this->numFixes--;
    }
}
