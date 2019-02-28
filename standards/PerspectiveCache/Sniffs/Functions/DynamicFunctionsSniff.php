<?php
/**
 * Warns about dynamic function usage.
 *
 * @author    Greg Sherwood <gsherwood@squiz.net>
 * @copyright 2006-2015 Squiz Pty Ltd (ABN 77 084 670 600)
 * @license   https://github.com/squizlabs/PHP_CodeSniffer/blob/master/licence.txt BSD Licence
 */

namespace PHP_CodeSniffer\Standards\Perspective\Sniffs\Functions;

use PHP_CodeSniffer\Sniffs\Sniff;
use PHP_CodeSniffer\Files\File;
use PHP_CodeSniffer\Util\Tokens;

class DynamicFunctionsSniff implements Sniff
{

    /**
     * A list of dynamic functions.
     *
     * The index is the function name and the value is the number
     * of arguments that make the call static. If the value is NULL,
     * it means the call is always dynamic.
     *
     * @var array<string, int|null>
     */
    private $functions = [
        'rand'         => null,
        'date'         => 2,
        'localtime'    => 1,
        'time'         => null,
        'microtime'    => null,
        'gettimeofday' => null,
        'getdate'      => 1,
        'gmdate'       => 2,
        'gmmktime'     => 6,
        'gmstrftime'   => 2,
        'idate'        => 2,
        'mktime'       => 6,
        'strftime'     => 2,
        'strtotime'    => 2,
        'curl_exec'    => null,
        'shuffle'      => null,
        'str_shuffle'  => null,
        'easter_date'  => 1,
        'easter_days'  => 1,
        'array_rand'   => null,
        'lcg_value'    => null,
        'gmp_random'   => null,
        'mt_rand'      => null,
        'random_int'   => null,
        'random_bytes' => null,
    ];


    /**
     * Returns an array of tokens this test wants to listen for.
     *
     * @return array
     */
    public function register()
    {
        $tokens = [T_OPEN_TAG];
        return $tokens;

    }//end register()


    /**
     * Processes this test, when one of its tokens is encountered.
     *
     * @param \PHP_CodeSniffer\Files\File $phpcsFile The file being scanned.
     * @param integer                     $stackPtr  The position of the current token in the
     *                                               stack passed in $tokens.
     *
     * @return void
     */
    public function process(File $phpcsFile, $stackPtr)
    {
        $namespace = $phpcsFile->config->getConfigData('namespace');
        $scopesEnd = $this->processScope($phpcsFile, $stackPtr, T_OPEN_TAG, $namespace);
        return $scopesEnd;

    }//end process()


    /**
     * Processes a certain scope and returns the end position of that scope.
     *
     * @param \PHP_CodeSniffer\Files\File $phpcsFile          The file being scanned.
     * @param integer                     $stackPtr           The start position of this scoped area be it file or
     *                                                        function scope.
     * @param integer|string              $areaTypeTokenCode  The token code of this area, eg. T_OPEN_TAG.
     *
     * @return integer
     */
    public function processScope(File $phpcsFile, int $stackPtr, $areaTypeTokenCode, string $namespace='')
    {
        $tokens = $phpcsFile->getTokens();
        if ($areaTypeTokenCode === T_OPEN_TAG) {
            end($tokens);
            $endPtr = key($tokens);

            // When namespace isn't given and we are asked to fix we need to find it by reading the file contents.
            if ($namespace === '' && $phpcsFile->fixer->enabled === true) {
                $namespacePtr = $phpcsFile->findNext([T_NAMESPACE], $stackPtr);
                if ($namespacePtr !== false) {
                    $firstNamespacePtr    = $phpcsFile->findNext([T_STRING], $namespacePtr);
                    if ($firstNamespacePtr === false) {
                        throw \Exception('Expected to find first namespace after T_NAMESPACE.');
                    }
                    $namespace = $tokens[$firstNamespacePtr]['content'];

                    $firstNamespaceSepPtr = $phpcsFile->findNext([T_NS_SEPARATOR], $firstNamespacePtr);
                    if ($firstNamespaceSepPtr === false) {
                        throw \Exception('Namespace expected to have 2 parts.');
                    }
                    $namespace .= $tokens[$firstNamespaceSepPtr]['content'];

                    $secondNamespacePtr   = $phpcsFile->findNext([T_STRING], $firstNamespaceSepPtr);
                    if ($secondNamespacePtr === false) {
                        throw \Exception('Expected to find namespace part after namespace separator.');
                    }

                    $namespace .= $tokens[$secondNamespacePtr]['content'];
                    $stackPtr   = $secondNamespacePtr;
                }
            }

            // Under normal PHP you can define functions (perhaps in a class or not) and closures.
            $subScopeSearchTokens = [T_FUNCTION, T_CLOSURE];
        } else {
            // If you are in a function or closure you can only define more closures.
            $stackPtr = $tokens[$stackPtr]['scope_opener'];
            $endPtr   = $tokens[$stackPtr]['scope_closer'];
            $subScopeSearchTokens = [T_CLOSURE];
        }

        $nextStackPtr        = null;
        $startScopeOpenerPtr = $stackPtr;
        do {
            $stackPtr = ($stackPtr + 1);

            // Look for any new scope for independent analysis.
            if (in_array($tokens[$stackPtr]['code'], $subScopeSearchTokens, true) === true) {
                $stackPtr = $this->processScope($phpcsFile, $stackPtr, $tokens[$stackPtr]['code'], $namespace);
                continue;
            }

            // See if we can find a call to say this is already not cacheable.
            $openBracket = $phpcsFile->findNext(Tokens::$emptyTokens, ($stackPtr + 1), null, true);
            if ($tokens[$openBracket]['code'] !== T_OPEN_PARENTHESIS
                || isset($tokens[$openBracket]['parenthesis_closer']) === false
            ) {
                if ($tokens[$openBracket]['code'] === T_DOUBLE_COLON
                    && $tokens[($openBracket - 1)]['content'] === 'Cache'
                    && $tokens[($openBracket + 1)]['content'] === 'noCache'
                ) {
                    // Code already marked as dynamic so no need to look further.
                    break;
                }

                // Not a function call so move onto the next token.
                continue;
            }

            $name = strtolower($tokens[$stackPtr]['content']);
            if (array_key_exists($name, $this->functions) === false) {
                // Not a function we think is dynamic.
                continue;
            }

            if ($this->functions[$name] === null) {
                $error = 'The %s() function makes the code block dynamic for all requests';
                $data  = [$tokens[$stackPtr]['content']];
                $fix   = $phpcsFile->addFixableError($error, $stackPtr, 'Found', $data);
                if ($fix === true) {
                    $phpcsFile->fixer->addContent($startScopeOpenerPtr, $namespace.'\framework\Cache::noCache();');

                    // 1 fix fixes all for this file/function so for speed just end now.
                    break;
                }

                continue;
            }

            // Count the number of arguments.
            $start = $phpcsFile->findNext(T_OPEN_PARENTHESIS, ($stackPtr + 1));
            if ($start === false || isset($tokens[$start]['parenthesis_closer']) === false) {
                continue;
            }

            $numArgs = 0;

            $end  = $tokens[$start]['parenthesis_closer'];
            $next = $phpcsFile->findNext(Tokens::$emptyTokens, ($start + 1), $end, true);
            if ($next !== false) {
                $numArgs = 1;
                for ($i = ($start + 1); $i < $end; $i++) {
                    if (isset($tokens[$i]['scope_closer']) === true
                        && $i === $tokens[$i]['scope_opener']
                    ) {
                        $i = $tokens[$i]['scope_closer'];
                    } else if (isset($tokens[$i]['bracket_closer']) === true
                        && $i === $tokens[$i]['bracket_opener']
                    ) {
                        $i = $tokens[$i]['bracket_closer'];
                    } else if (isset($tokens[$i]['parenthesis_closer']) === true
                        && $i === $tokens[$i]['parenthesis_opener']
                    ) {
                        $i = $tokens[$i]['parenthesis_closer'];
                    } else if ($tokens[$i]['code'] === T_COMMA) {
                        $numArgs++;
                    } else if ($tokens[$i]['code'] === T_ELLIPSIS) {
                        // By using the splat operator, we can't figure out the true number of arguments.
                        $error = 'The %s() function requires at least %s argument';
                        if ($this->functions[$name] !== 1) {
                            $error .= 's';
                        }

                        $error .= ' to make it a static call,';
                        $error .= ' but the call cannot be checked due to the use of argument unpacking';
                        $data   = [
                            $tokens[$stackPtr]['content'],
                            $this->functions[$name],
                        ];
                        $fix    = $phpcsFile->addFixableError($error, $stackPtr, 'FoundPossibleUnpackedStatic', $data);
                        if ($fix === true) {
                            $phpcsFile->fixer->addContent(
                                $startScopeOpenerPtr,
                                $namespace.'\framework\Cache::noCache();'
                            );

                            // 1 fix fixes all for this file/function so for speed just end now.
                            break;
                        }
                        continue;
                    }//end if
                }//end for
            }//end if

            if ($this->functions[$name] <= $numArgs) {
                continue;
            }

            $error = 'The %s() function with %s argument';
            if ($numArgs !== 1) {
                $error .= 's';
            }

            $error .= ' makes the code block dynamic for all requests; ';
            $error .= 'use at least %s argument';
            if ($this->functions[$name] !== 1) {
                $error .= 's';
            }

            $error .= ' to make it a static call';
            $data   = [
                $tokens[$stackPtr]['content'],
                $numArgs,
                $this->functions[$name],
            ];
            $fix    = $phpcsFile->addFixableError($error, $stackPtr, 'FoundPossibleStatic', $data);
            if ($fix === true) {
                $phpcsFile->fixer->addContentBefore(
                    $startScopeOpenerPtr,
                    $namespace.'\framework\Cache::noCache();'
                );

                // 1 fix fixes all for this file/function so for speed just end now.
                break;
            }


        } while ($stackPtr < $endPtr);

        return $endPtr;

    }//end processScope()


}//end class
