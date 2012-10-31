<?php

/**
 * EasyRdf
 *
 * LICENSE
 *
 * Copyright (c) 2012 Nicholas J Humfrey.
 * All rights reserved.
 *
 * Redistribution and use in source and binary forms, with or without
 * modification, are permitted provided that the following conditions are met:
 * 1. Redistributions of source code must retain the above copyright
 *    notice, this list of conditions and the following disclaimer.
 * 2. Redistributions in binary form must reproduce the above copyright notice,
 *    this list of conditions and the following disclaimer in the documentation
 *    and/or other materials provided with the distribution.
 * 3. The name of the author 'Nicholas J Humfrey" may be used to endorse or
 *    promote products derived from this software without specific prior
 *    written permission.
 *
 * THIS SOFTWARE IS PROVIDED BY THE COPYRIGHT HOLDERS AND CONTRIBUTORS "AS IS"
 * AND ANY EXPRESS OR IMPLIED WARRANTIES, INCLUDING, BUT NOT LIMITED TO, THE
 * IMPLIED WARRANTIES OF MERCHANTABILITY AND FITNESS FOR A PARTICULAR PURPOSE
 * ARE DISCLAIMED. IN NO EVENT SHALL THE COPYRIGHT OWNER OR CONTRIBUTORS BE
 * LIABLE FOR ANY DIRECT, INDIRECT, INCIDENTAL, SPECIAL, EXEMPLARY, OR
 * CONSEQUENTIAL DAMAGES (INCLUDING, BUT NOT LIMITED TO, PROCUREMENT OF
 * SUBSTITUTE GOODS OR SERVICES; LOSS OF USE, DATA, OR PROFITS; OR BUSINESS
 * INTERRUPTION) HOWEVER CAUSED AND ON ANY THEORY OF LIABILITY, WHETHER IN
 * CONTRACT, STRICT LIABILITY, OR TORT (INCLUDING NEGLIGENCE OR OTHERWISE)
 * ARISING IN ANY WAY OUT OF THE USE OF THIS SOFTWARE, EVEN IF ADVISED OF THE
 * POSSIBILITY OF SUCH DAMAGE.
 *
 * @package    EasyRdf
 * @copyright  Copyright (c) 2009-2012 Nicholas J Humfrey
 *             Copyright (c) 1997-2006 Aduna (http://www.aduna-software.com/)
 * @license    http://www.opensource.org/licenses/bsd-license.php
 * @version    $Id$
 */

/**
 * Class to parse RDFa with no external dependancies.
 *
 * http://www.w3.org/TR/rdfa-core/
 *
 * @package    EasyRdf
 * @copyright  Copyright (c) 2012 Nicholas J Humfrey
 * @license    http://www.opensource.org/licenses/bsd-license.php
 */
class EasyRdf_Parser_Rdfa extends EasyRdf_Parser
{
    const XML_NS = 'http://www.w3.org/XML/1998/namespace';

    /**
     * Constructor
     *
     * @return object EasyRdf_Parser_Rdfa
     */
    public function __construct()
    {
    }

    protected function resolve($uri)
    {
        if ($this->_baseUri) {
            return $this->_baseUri->resolve($uri);
        } else {
            return $uri;
        }
    }

    protected function addTriple($resource, $property, $value)
    {
        print "Adding triple: $resource -> $property -> ".$value['type'].':'.$value['value']."\n";
        $count = $this->_graph->add($resource, $property, $value);
        $this->_tripleCount += $count;
        return $count;
    }

    protected function printNode($node, $depth) {
        $indent = str_repeat('  ', $depth);
        print $indent;
        switch($node->nodeType) {
            case XML_ELEMENT_NODE: print 'node'; break;
            case XML_ATTRIBUTE_NODE: print 'attr'; break;
            case XML_TEXT_NODE: print 'text'; break;
            case XML_CDATA_SECTION_NODE: print 'cdata'; break;
            case XML_ENTITY_REF_NODE: print 'entref'; break;
            case XML_ENTITY_NODE: print 'entity'; break;
            case XML_PI_NODE: print 'pi'; break;
            case XML_COMMENT_NODE: print 'comment'; break;
            case XML_DOCUMENT_NODE: print 'doc'; break;
            case XML_DOCUMENT_TYPE_NODE: print 'doctype'; break;
            case XML_HTML_DOCUMENT_NODE: print 'html'; break;
            default: throw new Excpetion("unknown node type: ".$node->nodeType); break;
        }
        print ' '.$node->nodeName;
        print "\n";

        if ($node->hasAttributes()) {
            foreach($node->attributes as $attr) {
                print $indent.' '.$attr->nodeName." => ".$attr->nodeValue."\n";
            }
        }
    }

    protected function expandCurie($node, $context, $attr)
    {
        $value = $node->getAttribute($attr);
        if ($value === NULL)
            return NULL;

        if (preg_match("/^\[(.+)\]$/", $value, $matches)) {
            $value = $matches[1];
        }

        if (substr($value, 0, 2) === '_:') {
            # It is a bnode
            return $this->remapBnode(substr($value, 2));
        } elseif (preg_match("/^(\w+?):([\w\-]+)$/", $value, $matches)) {
            list (, $prefix, $local) = $matches;
            $prefix = strtolower($prefix);
            if (isset($context['namespaces'][$prefix])) {
                return $context['namespaces'][$prefix] . $local;
            } else {
                $uri = $node->lookupNamespaceURI($prefix);
                if ($uri) {
                    return $uri . $local;
                } else {
                    #FIXME: work out how to handle errors
                    error_log("Unknown namespace: $prefix");
                }
            }
        } elseif (isset($context['namespaces'][''])) {
            return $context['namespaces'][''] . $value;
        } else {
            return $this->resolve($value);
        }
    }

    protected function processNode($node, $context, $depth=1)
    {
        $this->printNode($node, $depth);
        $subject = NULL;
        $object = NULL;
        $incompleteRel = NULL;
        $incompleteRev = NULL;

        # FIXME: move this to Step 13
        if ($node->hasAttributes()) {

            // Step 2
            if ($node->hasAttribute('vocab')) {
                if ($vocab = $node->getAttribute('vocab')) {
                    $context['namespaces'][''] = $vocab;
                    $vocab = array('type' => 'uri', 'value' => $vocab );
                    $this->addTriple($this->_baseUri, 'rdfa:usesVocabulary', $vocab);
                } else {
                    $context['namespaces'][''] = NULL;
                }
            }

            // Step 3
            if ($node->hasAttribute('prefix')) {
                $prefix = $node->getAttribute('prefix');
                $context['namespaces'][strtolower($prefix)] = $local;
            }

            // Step 4
            if ($node->hasAttributeNS(self::XML_NS, 'lang')) {
                $context['lang'] = $node->getAttributeNS(self::XML_NS, 'lang');
            }

            if ($node->hasAttribute('lang')) {
                $context['lang'] = $node->getAttribute('lang');
            }

            if (!$node->hasAttribute('rel') and !$node->hasAttribute('rev')) {
                // Step 5
                if ($node->hasAttribute('about')) {
                    $subject = $this->expandCurie($node, $context, 'about');
                } elseif ($node->hasAttribute('src')) {
                    $subject = $this->expandCurie($node, $context, 'src');
                } elseif ($node->hasAttribute('resource')) {
                    $subject = $this->expandCurie($node, $context, 'resource');
                } elseif ($node->hasAttribute('href')) {
                    $subject = $this->expandCurie($node, $context, 'href');
                }

                if ($subject === NULL) {
                    $subject = $context['object'];
                }

            } else {
                // Step 6
                // If the current element does contain a @rel or @rev attribute, then the next step is to
                // establish both a value for new subject and a value for current object resource:
                if ($node->hasAttribute('about')) {
                    $subject = $this->expandCurie($node, $context, 'about');
                } elseif ($node->hasAttribute('src')) {
                    $subject = $this->expandCurie($node, $context, 'src');
                }

                if ($subject === NULL) {
                    $subject = $context['object'];
                }

                if ($node->hasAttribute('resource')) {
                    $object = $this->expandCurie($node, $context, 'resource');
                } elseif ($node->hasAttribute('href')) {
                    $object = $this->expandCurie($node, $context, 'href');
                }

                if ($node->hasAttribute('rev')) {
                    $rev = $this->expandCurie($node, $context, 'rev');
                }

                if ($node->hasAttribute('rel')) {
                    $rel = $this->expandCurie($node, $context, 'rel');
                }
            }


            // Step 9: Generate triples with given object
            if ($object) {
                if (isset($rev)) {
                    $this->addTriple(
                        $object,
                        $rev,
                        array('type' => 'uri', 'value' => $subject)
                    );
                }

                if (isset($rel)) {
                    $this->addTriple(
                        $subject,
                        $rel,
                        array('type' => 'uri', 'value' => $object)
                    );
                }
            } elseif (isset($rel) or isset($rev)) {
                // Step 10: Incomplete triples and bnode creation
                $object = $this->_graph->newBNodeId();
                if (isset($rel)) {
                    $incompleteRel = $rel;
                    print "Incomplete rel: $rel\n";
                }

                if (isset($rev)) {
                    $incompleteRev = $rev;
                    print "Incomplete rev: $rev\n";
                }
            }

            // Step 11
            if ($node->hasAttribute('property')) {
                $literal = array('type' => 'literal');
                $property = $this->expandCurie($node, $context, 'property');

                if ($node->hasAttribute('content')) {
                    $literal['value'] = $node->getAttribute('content');
                } else {
                    $literal['value'] = $node->textContent;
                }

                if ($node->hasAttribute('datatype')) {
                    $literal['datatype'] = $this->expandCurie($node, $context, 'datatype');
                }

                if ($context['lang']) {
                    $literal['lang'] = $context['lang'];
                }

                $this->addTriple($subject, $property, $literal);
            }

            // Step 12: Complete the incomplete triples from the evaluation context
            if ($subject and ($context['incompleteRel'] or $context['incompleteRev'])) {
                if ($context['incompleteRel']) {
                    $this->addTriple(
                        $context['subject'],
                        $context['incompleteRel'],
                        array('type' => 'uri', 'value' => $subject)
                    );
                }

                if ($context['incompleteRev']) {
                    $this->addTriple(
                        $subject,
                        $context['incompleteRev'],
                        array('type' => 'uri', 'value' => $context['subject'])
                    );
                }
            }
        }

        // Step 13
        if ($node->hasChildNodes()) {
            // Prepare a new evaluation context
            if ($object) {
                $context['object'] = $object;
            } elseif ($subject) {
                $context['object'] = $subject;
            } else {
                $context['object'] = $context['subject'];
            }
            if ($subject)
                $context['subject'] = $subject;
            $context['incompleteRel'] = $incompleteRel;
            $context['incompleteRev'] = $incompleteRev;
            foreach($node->childNodes as $child) {
                if ($child->nodeType == XML_ELEMENT_NODE)
                    $this->processNode($child, $context, $depth+1);
            }
        }
    }

    /**
     * Parse RDFa into an EasyRdf_Graph
     *
     * @param object EasyRdf_Graph $graph   the graph to load the data into
     * @param string               $data    the RDF document data
     * @param string               $format  the format of the input data
     * @param string               $baseUri the base URI of the data being parsed
     * @return integer             The number of triples added to the graph
     */
    public function parse($graph, $data, $format, $baseUri)
    {
        parent::checkParseParams($graph, $data, $format, $baseUri);

        if ($format != 'rdfa') {
            throw new EasyRdf_Exception(
                "EasyRdf_Parser_Rdfa does not support: $format"
            );
        }

        // Step 1: Initialise evaluation context
        $context = array(
            'namespaces' => array(),
            'skipElement' => false,
            'subject' => $this->_baseUri,
            'property' => NULL,
            'object' => NULL,
            'incompleteRel' => NULL,
            'incompleteRev' => NULL,
            'lang' => NULL,
            'datatype' => NULL
        );

        libxml_use_internal_errors(true);

        $doc = new DOMDocument();
        $doc->loadXML($data, LIBXML_NONET);
        $this->processNode($doc, $context);

        return $this->_tripleCount;
    }

}
