<?php
/**
 * Improved YAML Parser for PHP
 * Handles multiline strings properly
 */

class YamlParserV2 {
    public static function parseFile($filename) {
        if (!file_exists($filename)) {
            throw new Exception("File not found: $filename");
        }
        
        $content = file_get_contents($filename);
        return self::parse($content);
    }
    
    public static function parse($yaml) {
        $lines = explode("\n", $yaml);
        $result = [];
        $i = 0;
        
        while ($i < count($lines)) {
            $line = $lines[$i];
            
            // Skip empty lines and comments
            if (trim($line) === '' || strpos(trim($line), '#') === 0) {
                $i++;
                continue;
            }
            
            // Check for key with multiline value (|)
            if (preg_match('/^(\s*)([^:]+):\s*\|\s*$/', $line, $matches)) {
                $indent = strlen($matches[1]);
                $key = trim($matches[2]);
                $value = '';
                $i++;
                
                // Collect multiline content
                $contentIndent = -1;
                while ($i < count($lines)) {
                    $currentLine = $lines[$i];
                    
                    // If line starts with non-whitespace at same or less indent, we're done
                    if (trim($currentLine) !== '') {
                        $currentIndent = strlen($currentLine) - strlen(ltrim($currentLine));
                        
                        // First content line sets the content indent level
                        if ($contentIndent === -1) {
                            $contentIndent = $currentIndent;
                        }
                        
                        // If we hit a line with less indent than content, stop
                        if ($currentIndent <= $indent && preg_match('/^\s*[^:]+:/', $currentLine)) {
                            break;
                        }
                        
                        // Extract content after the content indent
                        if ($currentIndent >= $contentIndent) {
                            $value .= substr($currentLine, $contentIndent) . "\n";
                        }
                    } else {
                        // Preserve empty lines in multiline content
                        $value .= "\n";
                    }
                    
                    $i++;
                }
                
                $result[$key] = rtrim($value);
                continue;
            }
            
            // Check for simple key: value
            if (preg_match('/^(\s*)([^:]+):\s*(.*)$/', $line, $matches)) {
                $key = trim($matches[2]);
                $value = trim($matches[3]);
                
                // Remove quotes if present
                if ((strpos($value, '"') === 0 && strrpos($value, '"') === strlen($value) - 1) ||
                    (strpos($value, "'") === 0 && strrpos($value, "'") === strlen($value) - 1)) {
                    $value = substr($value, 1, -1);
                }
                
                $result[$key] = $value;
            }
            
            $i++;
        }
        
        return $result;
    }
}

// Make compatible with yaml_parse_file
if (!function_exists('yaml_parse_file')) {
    function yaml_parse_file($filename) {
        return YamlParserV2::parseFile($filename);
    }
}