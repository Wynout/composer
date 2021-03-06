<?php

/*
 * This file is part of Composer.
 *
 * (c) Nils Adermann <naderman@naderman.de>
 *     Jordi Boggiano <j.boggiano@seld.be>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Composer\Json;

/**
 * @author Jordi Boggiano <j.boggiano@seld.be>
 */
class JsonManipulator
{
    private static $RECURSE_BLOCKS = '(?:[^{}]*|\{(?:[^{}]*|\{(?:[^{}]*|\{(?:[^{}]*|\{[^{}]*\})*\})*\})*\})*';

    private $contents;
    private $newline;
    private $indent;

    public function __construct($contents)
    {
        $contents = trim($contents);
        if (!preg_match('#^\{(.*)\}$#s', $contents)) {
            throw new \InvalidArgumentException('The json file must be an object ({})');
        }
        $this->newline = false !== strpos($contents, "\r\n") ? "\r\n": "\n";
        $this->contents = $contents === '{}' ? '{' . $this->newline . '}' : $contents;
        $this->detectIndenting();
    }

    public function getContents()
    {
        return $this->contents . $this->newline;
    }

    public function addLink($type, $package, $constraint)
    {
        // no link of that type yet
        if (!preg_match('#"'.$type.'":\s*\{#', $this->contents)) {
            $this->addMainKey($type, $this->format(array($package => $constraint)));

            return true;
        }

        $linksRegex = '#("'.$type.'":\s*\{)([^}]+)(\})#s';
        if (!preg_match($linksRegex, $this->contents, $match)) {
            return false;
        }

        $links = $match[2];
        $packageRegex = str_replace('/', '\\\\?/', preg_quote($package));

        // link exists already
        if (preg_match('{"'.$packageRegex.'"\s*:}i', $links)) {
            $links = preg_replace('{"'.$packageRegex.'"(\s*:\s*)"[^"]+"}i', addcslashes(JsonFile::encode($package).'${1}"'.$constraint.'"', '\\'), $links);
        } elseif (preg_match('#[^\s](\s*)$#', $links, $match)) {
            // link missing but non empty links
            $links = preg_replace(
                '#'.$match[1].'$#',
                addcslashes(',' . $this->newline . $this->indent . $this->indent . JsonFile::encode($package).': '.JsonFile::encode($constraint) . $match[1], '\\'),
                $links
            );
        } else {
            // links empty
            $links = $this->newline . $this->indent . $this->indent . JsonFile::encode($package).': '.JsonFile::encode($constraint) . $links;
        }

        $this->contents = preg_replace($linksRegex, addcslashes('${1}'.$links.'$3', '\\'), $this->contents);

        return true;
    }

    public function addRepository($name, $config)
    {
        return $this->addSubNode('repositories', $name, $config);
    }

    public function removeRepository($name)
    {
        return $this->removeSubNode('repositories', $name);
    }

    public function addConfigSetting($name, $value)
    {
        return $this->addSubNode('config', $name, $value);
    }

    public function removeConfigSetting($name)
    {
        return $this->removeSubNode('config', $name);
    }

    public function addSubNode($mainNode, $name, $value)
    {
        // no main node yet
        if (!preg_match('#"'.$mainNode.'":\s*\{#', $this->contents)) {
            $this->addMainKey(''.$mainNode.'', $this->format(array($name => $value)));

            return true;
        }

        $subName = null;
        if (false !== strpos($name, '.')) {
            list($name, $subName) = explode('.', $name, 2);
        }

        // main node content not match-able
        $nodeRegex = '#("'.$mainNode.'":\s*\{)('.self::$RECURSE_BLOCKS.')(\})#s';
        if (!preg_match($nodeRegex, $this->contents, $match)) {
            return false;
        }

        $children = $match[2];

        // invalid match due to un-regexable content, abort
        if (!json_decode('{'.$children.'}')) {
            return false;
        }

        $that = $this;

        // child exists
        if (preg_match('{("'.preg_quote($name).'"\s*:\s*)([0-9.]+|null|true|false|"[^"]+"|\[[^\]]*\]|\{'.self::$RECURSE_BLOCKS.'\})(,?)}', $children, $matches)) {
            $children = preg_replace_callback('{("'.preg_quote($name).'"\s*:\s*)([0-9.]+|null|true|false|"[^"]+"|\[[^\]]*\]|\{'.self::$RECURSE_BLOCKS.'\})(,?)}', function ($matches) use ($name, $subName, $value, $that) {
                if ($subName !== null) {
                    $curVal = json_decode($matches[2], true);
                    $curVal[$subName] = $value;
                    $value = $curVal;
                }

                return $matches[1] . $that->format($value, 1) . $matches[3];
            }, $children);
        } elseif (preg_match('#[^\s](\s*)$#', $children, $match)) {
            if ($subName !== null) {
                $value = array($subName => $value);
            }

            // child missing but non empty children
            $children = preg_replace(
                '#'.$match[1].'$#',
                addcslashes(',' . $this->newline . $this->indent . $this->indent . JsonFile::encode($name).': '.$this->format($value, 1) . $match[1], '\\'),
                $children
            );
        } else {
            if ($subName !== null) {
                $value = array($subName => $value);
            }

            // children present but empty
            $children = $this->newline . $this->indent . $this->indent . JsonFile::encode($name).': '.$this->format($value, 1) . $children;
        }

        $this->contents = preg_replace($nodeRegex, addcslashes('${1}'.$children.'$3', '\\'), $this->contents);

        return true;
    }

    public function removeSubNode($mainNode, $name)
    {
        // no node
        if (!preg_match('#"'.$mainNode.'":\s*\{#', $this->contents)) {
            return true;
        }

        // empty node
        if (preg_match('#"'.$mainNode.'":\s*\{\s*\}#s', $this->contents)) {
            return true;
        }

        // no node content match-able
        $nodeRegex = '#("'.$mainNode.'":\s*\{)('.self::$RECURSE_BLOCKS.')(\})#s';
        if (!preg_match($nodeRegex, $this->contents, $match)) {
            return false;
        }

        $children = $match[2];

        // invalid match due to un-regexable content, abort
        if (!json_decode('{'.$children.'}')) {
            return false;
        }

        $subName = null;
        if (false !== strpos($name, '.')) {
            list($name, $subName) = explode('.', $name, 2);
        }

        // try and find a match for the subkey
        if (preg_match('{"'.preg_quote($name).'"\s*:}i', $children)) {
            // find best match for the value of "name"
            if (preg_match_all('{"'.preg_quote($name).'"\s*:\s*(?:[0-9.]+|null|true|false|"[^"]+"|\[[^\]]*\]|\{'.self::$RECURSE_BLOCKS.'\})}', $children, $matches)) {
                $bestMatch = '';
                foreach ($matches[0] as $match) {
                    if (strlen($bestMatch) < strlen($match)) {
                        $bestMatch = $match;
                    }
                }
                $childrenClean = preg_replace('{,\s*'.preg_quote($bestMatch).'}i', '', $children, -1, $count);
                if (1 !== $count) {
                    $childrenClean = preg_replace('{'.preg_quote($bestMatch).'\s*,?\s*}i', '', $childrenClean, -1, $count);
                    if (1 !== $count) {
                        return false;
                    }
                }
            }
        }

        // no child data left, $name was the only key in
        if (!trim($childrenClean)) {
            $this->contents = preg_replace($nodeRegex, '$1'.$this->newline.$this->indent.'}', $this->contents);

            // we have a subname, so we restore the rest of $name
            if ($subName !== null) {
                $curVal = json_decode('{'.$children.'}', true);
                unset($curVal[$name][$subName]);
                $this->addSubNode($mainNode, $name, $curVal[$name]);
            }

            return true;
        }

        $that = $this;
        $this->contents = preg_replace_callback($nodeRegex, function ($matches) use ($that, $name, $subName, $childrenClean) {
            if ($subName !== null) {
                $curVal = json_decode('{'.$matches[2].'}', true);
                unset($curVal[$name][$subName]);
                $childrenClean = substr($that->format($curVal, 0), 1, -1);
            }

            return $matches[1] . $childrenClean . $matches[3];
        }, $this->contents);

        return true;
    }

    public function addMainKey($key, $content)
    {
        if (preg_match('#[^{\s](\s*)\}$#', $this->contents, $match)) {
            $this->contents = preg_replace(
                '#'.$match[1].'\}$#',
                addcslashes(',' . $this->newline . $this->indent . JsonFile::encode($key). ': '. $content . $this->newline . '}', '\\'),
                $this->contents
            );
        } else {
            $this->contents = preg_replace(
                '#\}$#',
                addcslashes($this->indent . JsonFile::encode($key). ': '.$content . $this->newline . '}', '\\'),
                $this->contents
            );
        }
    }

    public function format($data, $depth = 0)
    {
        if (is_array($data)) {
            reset($data);

            if (is_numeric(key($data))) {
                foreach ($data as $key => $val) {
                    $data[$key] = $this->format($val, $depth + 1);
                }

                return '['.implode(', ', $data).']';
            }

            $out = '{' . $this->newline;
            $elems = array();
            foreach ($data as $key => $val) {
                $elems[] = str_repeat($this->indent, $depth + 2) . JsonFile::encode($key). ': '.$this->format($val, $depth + 1);
            }

            return $out . implode(','.$this->newline, $elems) . $this->newline . str_repeat($this->indent, $depth + 1) . '}';
        }

        return JsonFile::encode($data);
    }

    protected function detectIndenting()
    {
        if (preg_match('{^(\s+)"}', $this->contents, $match)) {
            $this->indent = $match[1];
        } else {
            $this->indent = '    ';
        }
    }
}
