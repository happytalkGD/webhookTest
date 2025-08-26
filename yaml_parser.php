<?php
/**
 * Simple YAML Parser for PHP
 * Handles basic YAML structure without external dependencies
 */

class SimpleYamlParser {
    /**
     * Parse YAML file
     */
    public static function parseFile($filename) {
        if (!file_exists($filename)) {
            throw new Exception("File not found: $filename");
        }
        
        $content = file_get_contents($filename);
        return self::parse($content);
    }
    
    /**
     * Parse YAML string
     */
    public static function parse($yaml) {
        $lines = explode("\n", $yaml);
        $result = [];
        $currentKey = null;
        $currentValue = '';
        $isMultiline = false;
        $indent = 0;
        
        foreach ($lines as $line) {
            // Skip comments and empty lines
            if (trim($line) === '' || strpos(trim($line), '#') === 0) {
                if ($isMultiline) {
                    $currentValue .= "\n";
                }
                continue;
            }
            
            // Check for multiline indicator |
            if (preg_match('/^(\s*)([^:]+):\s*\|/', $line, $matches)) {
                if ($currentKey !== null && $isMultiline) {
                    $result[$currentKey] = rtrim($currentValue, "\n");
                }
                
                $currentKey = trim($matches[2]);
                $currentValue = '';
                $isMultiline = true;
                $indent = strlen($matches[1]);
                continue;
            }
            
            // Check for regular key-value
            if (preg_match('/^(\s*)([^:]+):\s*(.*)$/', $line, $matches)) {
                $lineIndent = strlen($matches[1]);
                
                // If we were in multiline mode, save it
                if ($currentKey !== null && $isMultiline) {
                    $result[$currentKey] = rtrim($currentValue, "\n");
                    $isMultiline = false;
                }
                
                $key = trim($matches[2]);
                $value = trim($matches[3]);
                
                // Remove quotes if present
                if ((strpos($value, '"') === 0 && strrpos($value, '"') === strlen($value) - 1) ||
                    (strpos($value, "'") === 0 && strrpos($value, "'") === strlen($value) - 1)) {
                    $value = substr($value, 1, -1);
                }
                
                $result[$key] = $value;
                $currentKey = $key;
                continue;
            }
            
            // If we're in multiline mode, append the line
            if ($isMultiline) {
                // Remove the base indent from multiline content
                $lineIndent = 0;
                while ($lineIndent < strlen($line) && $line[$lineIndent] === ' ') {
                    $lineIndent++;
                }
                
                if ($lineIndent > $indent) {
                    // For multiline content, preserve the line as-is after the indent
                    $contentStart = $indent + 2; // Skip base indent + 2 spaces
                    if ($contentStart < strlen($line)) {
                        $currentValue .= substr($line, $contentStart) . "\n";
                    } else {
                        $currentValue .= "\n";
                    }
                } else if (trim($line) !== '') {
                    // End of multiline block
                    $result[$currentKey] = rtrim($currentValue, "\n");
                    $isMultiline = false;
                    $currentKey = null;
                }
            }
        }
        
        // Save any remaining multiline content
        if ($currentKey !== null && $isMultiline) {
            $result[$currentKey] = rtrim($currentValue, "\n");
        }
        
        return $result;
    }
}

// Make function available globally for compatibility
if (!function_exists('yaml_parse_file')) {
    function yaml_parse_file($filename) {
        return SimpleYamlParser::parseFile($filename);
    }
}