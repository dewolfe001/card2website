<?php

/**
 * Load and parse .env file, converting key-value pairs to PHP defines
 * 
 * @param string $envFile Path to the .env file
 * @return bool True if successful, false otherwise
 */
function loadEnvFile($envFile = '.env') {
    // Check if file exists
    if (!file_exists($envFile)) {
        throw new Exception("Environment file not found: $envFile");
    }
    
    // Read file contents
    $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    
    if ($lines === false) {
        throw new Exception("Unable to read environment file: $envFile");
    }
    
    foreach ($lines as $line) {
        // Skip comments and empty lines
        $line = trim($line);
        if (empty($line) || $line[0] === '#') {
            continue;
        }
        
        // Parse key=value pairs
        if (strpos($line, '=') !== false) {
            list($key, $value) = parseEnvLine($line);
            
            // Only define if not already defined
            if (!defined($key)) {
                define($key, $value);
            }
            // Set environment variable for functions using getenv()
            if (getenv($key) === false) {
                putenv("$key=$value");
                $_ENV[$key] = $value;
                $_SERVER[$key] = $value;
            }
        }
    }
    
    return true;
}

/**
 * Parse a single line from .env file
 * 
 * @param string $line The line to parse
 * @return array Array containing [key, value]
 */
function parseEnvLine($line) {
    // Find the first = sign
    $separatorPos = strpos($line, '=');
    $key = trim(substr($line, 0, $separatorPos));
    $value = trim(substr($line, $separatorPos + 1));
    
    // Remove quotes if present
    $value = removeQuotes($value);
    
    return [$key, $value];
}

/**
 * Remove surrounding quotes from a value
 * 
 * @param string $value The value to process
 * @return string The value without surrounding quotes
 */
function removeQuotes($value) {
    $length = strlen($value);
    
    if ($length >= 2) {
        $firstChar = $value[0];
        $lastChar = $value[$length - 1];
        
        // Remove single or double quotes
        if (($firstChar === '"' && $lastChar === '"') || 
            ($firstChar === "'" && $lastChar === "'")) {
            return substr($value, 1, $length - 2);
        }
    }
    
    return $value;
}

// Load environment variables (call AFTER functions are defined)
try {
    loadEnvFile('.env');
} catch (Exception $e) {
    // Handle missing .env file gracefully
    error_log("Warning: " . $e->getMessage());
}

// Database connection using PDO
$host = defined('DB_HOST') ? DB_HOST : 'localhost';
$db   = defined('DB_NAME') ? DB_NAME : 'card2website';
$user = defined('DB_USER') ? DB_USER : 'root';
$pass = defined('DB_PASS') ? DB_PASS : '';
$charset = 'utf8mb4';

$dsn = "mysql:host=$host;dbname=$db;charset=$charset";
$options = [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES   => false,
];

try {
    $pdo = new PDO($dsn, $user, $pass, $options);
} catch (PDOException $e) {
    die('Database connection failed: ' . $e->getMessage());
}

?>
