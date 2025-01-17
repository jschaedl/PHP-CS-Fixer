<?php

/*
 * This file is part of PHP CS Fixer.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *     Dariusz Rumiński <dariusz.ruminski@gmail.com>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace PhpCsFixer\Fixer\FunctionNotation;

use PhpCsFixer\AbstractFixer;
use PhpCsFixer\FixerDefinition\CodeSample;
use PhpCsFixer\FixerDefinition\FixerDefinition;
use PhpCsFixer\Preg;
use PhpCsFixer\Tokenizer\Token;
use PhpCsFixer\Tokenizer\Tokens;

/**
 * @author Kuba Werłos <werlos@gmail.com>
 */
final class SingleLineThrowFixer extends AbstractFixer
{
    /**
     * @internal
     */
    const REMOVE_WHITESPACE_AFTER_TOKENS = ['['];

    /**
     * @internal
     */
    const REMOVE_WHITESPACE_AROUND_TOKENS = ['(', [T_DOUBLE_COLON]];

    /**
     * @internal
     */
    const REMOVE_WHITESPACE_BEFORE_TOKENS = [')',  ']', ',', ';'];

    /**
     * {@inheritdoc}
     */
    public function getDefinition()
    {
        return new FixerDefinition(
            'Throwing exception must be done in single line.',
            [
                new CodeSample("<?php\nthrow new Exception(\n    'Error.',\n    500\n);\n"),
            ]
        );
    }

    /**
     * {@inheritdoc}
     */
    public function isCandidate(Tokens $tokens)
    {
        return $tokens->isTokenKindFound(T_THROW);
    }

    /**
     * {@inheritdoc}
     *
     * Must run before BracesFixer, ConcatSpaceFixer.
     */
    public function getPriority()
    {
        // must be fun before ConcatSpaceFixer
        return 36;
    }

    /**
     * {@inheritdoc}
     */
    protected function applyFix(\SplFileInfo $file, Tokens $tokens)
    {
        for ($index = 0, $count = $tokens->count(); $index < $count; ++$index) {
            if (!$tokens[$index]->isGivenKind(T_THROW)) {
                continue;
            }

            /** @var int $endCandidateIndex */
            $endCandidateIndex = $tokens->getNextTokenOfKind($index, [';', '(', '{']);

            while ($tokens[$endCandidateIndex]->equals('(')) {
                $closingBraceIndex = $tokens->findBlockEnd(Tokens::BLOCK_TYPE_PARENTHESIS_BRACE, $endCandidateIndex);
                $endCandidateIndex = $tokens->getNextTokenOfKind($closingBraceIndex, [';', '(', '{']);
            }

            $this->trimNewLines($tokens, $index, $endCandidateIndex);
        }
    }

    /**
     * @param int $startIndex
     * @param int $endIndex
     */
    private function trimNewLines(Tokens $tokens, $startIndex, $endIndex)
    {
        for ($index = $startIndex; $index < $endIndex; ++$index) {
            $content = $tokens[$index]->getContent();

            if ($tokens[$index]->isGivenKind(T_COMMENT)) {
                if (0 === strpos($content, '//')) {
                    $content = '/*'.substr($content, 2).' */';
                    $tokens->clearAt($index + 1);
                } elseif (0 === strpos($content, '#')) {
                    $content = '/*'.substr($content, 1).' */';
                    $tokens->clearAt($index + 1);
                } elseif (false !== Preg::match('/\R/', $content)) {
                    $content = Preg::replace('/\R/', ' ', $content);
                }

                $tokens[$index] = new Token([T_COMMENT, $content]);

                continue;
            }

            if (!$tokens[$index]->isGivenKind(T_WHITESPACE)) {
                continue;
            }

            if (0 === Preg::match('/\R/', $content)) {
                continue;
            }

            $prevIndex = $tokens->getNonEmptySibling($index, -1);

            if ($this->isPreviousTokenToClear($tokens[$prevIndex])) {
                $tokens->clearAt($index);

                continue;
            }

            $nextIndex = $tokens->getNonEmptySibling($index, 1);

            if (
                $this->isNextTokenToClear($tokens[$nextIndex])
                && !$tokens[$prevIndex]->isGivenKind(T_FUNCTION)
            ) {
                $tokens->clearAt($index);

                continue;
            }

            $tokens[$index] = new Token([T_WHITESPACE, ' ']);
        }
    }

    /**
     * @return bool
     */
    private function isPreviousTokenToClear(Token $token)
    {
        static $tokens = null;

        if (null === $tokens) {
            $tokens = array_merge(self::REMOVE_WHITESPACE_AFTER_TOKENS, self::REMOVE_WHITESPACE_AROUND_TOKENS);
        }

        return $token->equalsAny($tokens) || $token->isObjectOperator();
    }

    /**
     * @return bool
     */
    private function isNextTokenToClear(Token $token)
    {
        static $tokens = null;

        if (null === $tokens) {
            $tokens = array_merge(self::REMOVE_WHITESPACE_AROUND_TOKENS, self::REMOVE_WHITESPACE_BEFORE_TOKENS);
        }

        return $token->equalsAny($tokens) || $token->isObjectOperator();
    }
}
