<?php
/**
 * Checks the format of the file header.
 *
 * @author    Greg Sherwood <gsherwood@squiz.net>
 * @copyright 2006-2019 Squiz Pty Ltd (ABN 77 084 670 600)
 * @license   https://github.com/squizlabs/PHP_CodeSniffer/blob/master/licence.txt BSD Licence
 */

namespace PHP_CodeSniffer\Standards\PSR12\Sniffs\Files;

use PHP_CodeSniffer\Sniffs\Sniff;
use PHP_CodeSniffer\Files\File;
use PHP_CodeSniffer\Util\Tokens;

class FileHeaderSniff implements Sniff
{


    /**
     * Returns an array of tokens this test wants to listen for.
     *
     * @return array
     */
    public function register()
    {
        return [T_OPEN_TAG];

    }//end register()


    /**
     * Processes this sniff when one of its tokens is encountered.
     *
     * @param \PHP_CodeSniffer\Files\File $phpcsFile The file being scanned.
     * @param int                         $stackPtr  The position of the current
     *                                               token in the stack.
     *
     * @return void
     */
    public function process(File $phpcsFile, $stackPtr)
    {
        $tokens = $phpcsFile->getTokens();

        /*
            First, gather information about the statements inside
            the file header.
        */

        $headerLines   = [];
        $headerLines[] = [
            'type'  => 'tag',
            'start' => $stackPtr,
            'end'   => $stackPtr,
        ];

        $next = $phpcsFile->findNext(T_WHITESPACE, ($stackPtr + 1), null, true);
        if ($next === false) {
            return;
        }

        $foundDocblock = false;

        do {
            switch ($tokens[$next]['code']) {
            case T_DOC_COMMENT_OPEN_TAG:
                if ($foundDocblock === true) {
                    // Found a second docblock, so start of code.
                    break(2);
                }

                // Make sure this is not a code-level docblock.
                $end      = $tokens[$next]['comment_closer'];
                $docToken = $phpcsFile->findNext(Tokens::$emptyTokens, ($end + 1), null, true);
                if (isset(Tokens::$scopeOpeners[$tokens[$docToken]['code']]) === false) {
                    $foundDocblock = true;
                    $headerLines[] = [
                        'type'  => 'docblock',
                        'start' => $next,
                        'end'   => $end,
                    ];
                }

                $next = $end;
                break;
            case T_DECLARE:
            case T_NAMESPACE:
                $end = $phpcsFile->findEndOfStatement($next);

                $headerLines[] = [
                    'type'  => substr(strtolower($tokens[$next]['type']), 2),
                    'start' => $next,
                    'end'   => $end,
                ];

                $next = $end;
                break;
            case T_USE:
                $type    = 'use';
                $useType = $phpcsFile->findNext(Tokens::$emptyTokens, ($next + 1), null, true);
                if ($useType !== false && $tokens[$useType]['code'] === T_STRING) {
                    $content = strtolower($tokens[$useType]['content']);
                    if ($content === 'function' || $content === 'const') {
                        $type .= ' '.$content;
                    }
                }

                $end = $phpcsFile->findEndOfStatement($next);

                $headerLines[] = [
                    'type'  => $type,
                    'start' => $next,
                    'end'   => $end,
                ];

                $next = $end;
                break;
            default:
                // Skip comments as PSR-12 doesn't say if these are allowed or not.
                if (isset(Tokens::$commentTokens[$tokens[$next]['code']]) === true) {
                    $next = $phpcsFile->findNext(Tokens::$commentTokens, ($next + 1), null, true);
                    $next--;
                    break;
                }

                // We found the start of the main code block.
                break(2);
            }//end switch

            $next = $phpcsFile->findNext(T_WHITESPACE, ($next + 1), null, true);
        } while ($next !== false);

        /*
            Next, check the spacing and grouping of the statements
            inside each header block.
        */

        $found = [];

        foreach ($headerLines as $i => $line) {
            if (isset($headerLines[($i + 1)]) === false
                || $headerLines[($i + 1)]['type'] !== $line['type']
            ) {
                // We're at the end of the current header block.
                // Make sure there is a single blank line after
                // this block.
                $next = $phpcsFile->findNext(T_WHITESPACE, ($line['end'] + 1), null, true);
                if ($tokens[$next]['line'] !== ($tokens[$line['end']]['line'] + 2)) {
                    $error = 'Header blocks must be followed by a single blank line';
                    $phpcsFile->addError($error, $line['end'], 'SpacingAfterBlock');
                }

                // Make sure we haven't seen this next block before.
                if (isset($headerLines[($i + 1)]) === true
                    && isset($found[$headerLines[($i + 1)]['type']]) === true
                ) {
                    $error  = 'Similar statements must be grouped together inside header blocks; ';
                    $error .= 'the first "%s" statement was found on line %s';
                    $data   = [
                        $headerLines[($i + 1)]['type'],
                        $tokens[$found[$headerLines[($i + 1)]['type']]['start']]['line'],
                    ];
                    $phpcsFile->addError($error, $headerLines[($i + 1)]['start'], 'IncorrectGrouping', $data);
                }
            } else if ($headerLines[($i + 1)]['type'] === $line['type']) {
                // Still in the same block, so make sure there is no
                // blank line after this statement.
                $next = $phpcsFile->findNext(T_WHITESPACE, ($line['end'] + 1), null, true);
                if ($tokens[$next]['line'] > ($tokens[$line['end']]['line'] + 1)) {
                    $error = 'Header blocks must not contain blank lines';
                    $phpcsFile->addError($error, $line['end'], 'SpacingInsideBlock');
                }
            }//end if

            if (isset($found[$line['type']]) === false) {
                $found[$line['type']] = $line;
            }
        }//end foreach

        /*
            Finally, check that the order of the header blocks
            is correct:
                Opening php tag.
                File-level docblock.
                One or more declare statements.
                The namespace declaration of the file.
                One or more class-based use import statements.
                One or more function-based use import statements.
                One or more constant-based use import statements.
        */

        $blockOrder = [
            'tag'          => 'opening PHP tag',
            'docblock'     => 'file-level docblock',
            'declare'      => 'declare statements',
            'namespace'    => 'namespace declaration',
            'use'          => 'class-based use imports',
            'use function' => 'function-based use imports',
            'use const'    => 'constant-based use imports',
        ];

        foreach (array_keys($found) as $type) {
            if ($type === 'tag') {
                // The opening tag is always in the correct spot.
                continue;
            }

            do {
                $orderedType = next($blockOrder);
            } while ($orderedType !== false && key($blockOrder) !== $type);

            if ($orderedType === false) {
                // We didn't find the block type in the rest of the
                // ordered array, so it is out of place.
                // Error and reset the array to the correct position
                // so we can check the next block.
                reset($blockOrder);
                $prevValidType = 'tag';
                do {
                    $orderedType = next($blockOrder);
                    if (isset($found[key($blockOrder)]) === true
                        && key($blockOrder) !== $type
                    ) {
                        $prevValidType = key($blockOrder);
                    }
                } while ($orderedType !== false && key($blockOrder) !== $type);

                $error = 'The %s must follow the %s in the file header';
                $data  = [
                    $blockOrder[$type],
                    $blockOrder[$prevValidType],
                ];
                $phpcsFile->addError($error, $found[$type]['start'], 'IncorrectOrder', $data);
            }//end if
        }//end foreach

    }//end process()


}//end class
