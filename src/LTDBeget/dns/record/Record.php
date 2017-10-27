<?php
/**
 * @author: Viskov Sergey
 * @date  : 14.04.16
 * @time  : 2:33
 */

namespace LTDBeget\dns\record;

use LTDBeget\ascii\AsciiChar;
use LTDBeget\dns\SyntaxErrorException;
use LTDBeget\stringstream\StringStream;

/**
 * Class Record
 *
 * @package LTDBeget\dns\record
 */
class Record
{
    /**
     * @var StringStream
     */
    private $stream;
    /**
     * @var string
     */
    private $globalOrigin;
    /**
     * @var string
     */
    private $globalTtl;

    /**
     * @var array
     */
    private $tokens = [];
    /**
     * @var bool
     */
    private $isFirst;
    /**
     * @var string
     */
    private $previousName;
    /**
     * @var array
     */
    private $options = [];

    /**
     * Record constructor.
     *
     * @param StringStream $stream
     * @param string|NULL  $globalOrigin
     * @param string|NULL  $globalTtl
     * @param bool         $isFirst
     * @param string       $previousName
     */
    public function __construct
    (
        StringStream $stream,
        string $globalOrigin = NULL,
        string $globalTtl = NULL,
        bool $isFirst = false,
        string $previousName = NULL,
        array $options = []
    )
    {
        $this->stream       = $stream;
        $this->globalOrigin = $globalOrigin;
        $this->globalTtl    = $globalTtl;
        $this->isFirst      = $isFirst;
        $this->previousName = $previousName;
        $this->options      = $options;
    }

    /**
     * @return array
     */
    public function tokenize() : array
    {
        if ($this->isRecordClass()) {
            if (!empty($this->previousName)) {
                $this->tokens['NAME'] = $this->previousName;
            } else {
                throw new SyntaxErrorException($this->stream);
            }

            if (!empty($this->globalTtl)) {
                $this->tokens['TTL'] = $this->globalTtl;
            } else {
                throw new SyntaxErrorException($this->stream);
            }
            goto in;
        }

        $this->defaultExtractor('NAME');
        $this->stream->ignoreHorizontalSpace();

        if ($this->isRecordClass()) {
            if (!empty($this->globalTtl)) {
                $this->tokens['TTL'] = $this->globalTtl;
            } elseif (!empty($this->previousName)) {
                $this->tokens['TTL']  = $this->tokens['NAME'];
                $this->tokens['NAME'] = $this->previousName;
            } else {
                throw new SyntaxErrorException($this->stream);
            }
            goto in;
        }
        
        $this->defaultExtractor('TTL');
        $this->stream->ignoreHorizontalSpace();
        in:
        $this->extractClass();
        
        $this->stream->ignoreHorizontalSpace();
        $this->extractRData();
        
        if($this->globalOrigin) {
            if($this->tokens['NAME'] === '@') {
                // handle the root character
                if(!empty($this->options['relativeToOrigin'])) {
                    $this->tokens['NAME'] = $this->globalOrigin;
                }
            } elseif($this->tokens['NAME'] == $this->globalOrigin) {
                // handle cases of origin domain
                if(!empty($this->options['relativeToOrigin'])) {
                    $this->tokens['NAME'] = '@';
                }
            }
            elseif(substr($this->tokens['NAME'], -1) === '.') {
                // handle cases where record is a child of global origin
                if(!empty($this->options['relativeToOrigin'])) {
                    $matches = [];
                    if(preg_match('/^(.*)\.'.preg_quote($this->globalOrigin).'$/', $this->tokens['NAME'], $matches)) {
                        $this->tokens['NAME'] = $matches[1];
                    }
                }
            } else {
                // the rest of cases
                if(empty($this->options['relativeToOrigin'])) {
                    if($this->globalOrigin === '.') {
                        $this->tokens['NAME'] .= $this->globalOrigin;
                    } else {
                        $this->tokens['NAME'] .= '.'.$this->globalOrigin;
                    }
                }
            }
        }
        
        return $this->tokens;
    }

    /**
     * @return bool
     */
    private function isRecordClass() : bool
    {
        if ($this->stream->currentAscii()->is(AsciiChar::I_UPPERCASE())) {
            $this->stream->next();
            if ($this->stream->currentAscii()->is(AsciiChar::N_UPPERCASE())) {
                $this->stream->next();
                if ($this->stream->currentAscii()->isHorizontalSpace()) {
                    $this->stream->previous();
                    $this->stream->previous();

                    return true;
                }
            } else {
                $this->stream->previous();
            }
        }

        return false;
    }

    /**
     * @param string $tokenName
     */
    private function defaultExtractor(string $tokenName)
    {
        if (!array_key_exists($tokenName, $this->tokens)) {
            $this->tokens[$tokenName] = '';
        }

        start:
        $char = $this->stream->currentAscii();
        if ($char->isPrintableChar() && !$char->isWhiteSpace()) {
            $this->tokens[$tokenName] .= $this->stream->current();
            $this->stream->next();
            goto start;
        }
    }

    private function extractClass()
    {
        if ($this->stream->currentAscii()->is(AsciiChar::I_UPPERCASE)) {
            $this->stream->next();
            if ($this->stream->currentAscii()->is(AsciiChar::N_UPPERCASE)) {
                $this->stream->next();
                
                $this->stream->ignoreHorizontalSpace();
                $this->defaultExtractor('TYPE');
                $this->stream->ignoreHorizontalSpace();
            
            } else {
                throw new SyntaxErrorException($this->stream);
            }
        } else {
            if($this->isFirst) {
                throw new SyntaxErrorException($this->stream);
            } else {
                if(RData::isKnownType($this->tokens['NAME']) && ! RData::isKnownType($this->tokens['TTL'])) {
                    // no ttl and no origin in record, and in TTL Rdata
                    last_chance:
                    if($this->previousName && $this->globalTtl) {
                        $this->tokens['TYPE'] = $this->tokens['NAME'];
                        $this->tokens['NAME'] = $this->previousName;
                        $this->tokens['TTL']  = $this->globalTtl;
                    } else {
                        throw new SyntaxErrorException($this->stream);
                    }
                } elseif(!RData::isKnownType($this->tokens['NAME']) && RData::isKnownType($this->tokens['TTL'])) {
                    $this->tokens['TYPE'] = $this->tokens['TTL'];
                    if($this->previousName && ! $this->globalTtl) {
                        $this->tokens['TTL']  =  $this->tokens['NAME'];
                        $this->tokens['NAME'] = $this->previousName;
                    } elseif(! $this->previousName && $this->globalTtl) {
                        $this->tokens['TTL'] =  $this->globalTtl;
                    } elseif($this->previousName && $this->globalTtl) {
                        $this->tokens['TTL'] = $this->globalTtl;
                    } else {
                        throw new SyntaxErrorException($this->stream);
                    }

                } elseif(RData::isKnownType($this->tokens['NAME']) && RData::isKnownType($this->tokens['TTL'])) {
                    goto last_chance;
                } else {
                    throw new SyntaxErrorException($this->stream);
                }

                do {
                    $char = $this->stream->currentAscii();
                    $this->stream->previous();
                } while($char->isWhiteSpace());

                do {
                    $char = $this->stream->currentAscii();
                    $this->stream->previous();
                } while($char->isPrintableChar() && ! $char->isHorizontalSpace());
            }
        }
    }

    private function extractRData()
    {
        $this->tokens['RDATA'] = (new RData($this->stream, $this->tokens['TYPE']))->tokenize();
    }
}