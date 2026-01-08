<?php
/**
 * Simple Website Generator
 * Routes requests to HTML files in the content directory
 */

/**
 * This is the main logic of the engine
 * @return void
 */
if (!function_exists('process')) {
    function process($projectRoot = null)
    {
        /**
         * Execute action class method based on HTTP request method
         */
        function executeAction($path, $actionsDir)
        {
            // Build the action file path
            $actionFile = $actionsDir . '/' . $path . '.php';

            // Check if action file exists
            if (!file_exists($actionFile) || !is_file($actionFile)) {
                return []; // No action file, return empty data
            }

            // Include the action file
            require_once $actionFile;

            // Build the class name from path (e.g., "contact" -> "Contact")
            $className = basename($path);
            $className = str_replace(['-', '_'], ' ', $className);
            $className = ucwords($className);
            $className = str_replace(' ', '', $className);

            // Check if class exists
            if (!class_exists($className)) {
                return []; // Class not found, return empty data
            }

            // Instantiate the action class
            $action = new $className();

            // Get the request method and build the method name
            $requestMethod = strtolower($_SERVER['REQUEST_METHOD']);
            $methodName = $requestMethod;

            // Execute the action method if it exists
            if (method_exists($action, $methodName)) {
                $result = $action->$methodName();
                // If result is an array, return it; otherwise return empty array
                return is_array($result) ? $result : [];
            }

            // If specific method doesn't exist, try the all() method as fallback
            if (method_exists($action, 'all')) {
                $result = $action->all();
                // If result is an array, return it; otherwise return empty array
                return is_array($result) ? $result : [];
            }

            return [];
        }

        /**
         * Serve a file with appropriate headers
         */
        function serveFile($filePath, $actionData = [])
        {
            header('Content-Type: text/html; charset=UTF-8');

            // Read the file content
            $content = file_get_contents($filePath);

            // Process includes (without data context - top level)
            $content = processIncludes($content, dirname($filePath), $actionData);

            // Process CSRF tokens
            $content = processCsrfTokens($content);

            // Process flash messages
            $content = processFlashMessages($content);

            // Process date shortcodes
            $content = processDateShortcodes($content);

            // Process template variables and loops
            $content = processTemplate($content, $actionData);

            echo $content;
            exit;
        }

        /**
         * Process template variables and foreach loops
         */
        function processTemplate($content, $data = [])
        {
            // First, process foreach loops (which will handle if statements inside them)
            $content = processForeachLoops($content, $data);

            // Then, process any remaining if statements (outside loops)
            $content = processIfStatements($content, $data);

            // Finally, process simple variables
            $content = processVariables($content, $data);

            return $content;
        }

        /**
         * Process if statements in template
         * Supports: <!--if($variable)-->...<!--endif-->
         * Supports: <!--if(!$variable)-->...<!--endif-->
         * Supports: <!--if($array[key])-->...<!--endif-->
         */
        function processIfStatements($content, $data)
        {
            // Pattern to match if statements with negation: <!--if(!$variable)-->
            $pattern = '/<!--\s*if\s*\(\s*!\s*\$(\w+)(?:\[(["\']?)([^\]]+)\2\])?\s*\)\s*-->(.*?)<!--\s*endif\s*-->/is';

            $content = preg_replace_callback($pattern, function ($matches) use ($data) {
                $varName = $matches[1];
                $key = isset($matches[3]) && $matches[3] !== '' ? $matches[3] : null;
                $ifContent = $matches[4];

                $value = null;
                $found = false;

                // Check if it's array access
                if ($key !== null) {
                    // Check if $varName exists and is an array with the key
                    if (isset($data[$varName]) && is_array($data[$varName]) && array_key_exists($key, $data[$varName])) {
                        $value = $data[$varName][$key];
                        $found = true;
                    }
                } else {
                    // Simple variable
                    if (isset($data[$varName])) {
                        $value = $data[$varName];
                        $found = true;
                    }
                }

                // If variable wasn't found, don't show content
                if (!$found) {
                    return '';
                }

                // Negation: show content if value is empty, null, false, or 0
                if (empty($value) && $value !== '0' && $value !== 0) {
                    return $ifContent;
                }

                return ''; // Condition false, return empty
            }, $content);

            // Pattern to match if statements without negation: <!--if($variable)-->
            $pattern = '/<!--\s*if\s*\(\s*\$(\w+)(?:\[(["\']?)([^\]]+)\2\])?\s*\)\s*-->(.*?)<!--\s*endif\s*-->/is';

            $content = preg_replace_callback($pattern, function ($matches) use ($data) {
                $varName = $matches[1];
                $key = isset($matches[2]) && $matches[2] !== '' ? trim($matches[2], '\'"') : null;
                $ifContent = $matches[3];

                $value = null;
                $found = false;

                // Check if it's array access
                if ($key !== null) {
                    // Check if $varName exists and is an array with the key
                    if (isset($data[$varName]) && is_array($data[$varName]) && array_key_exists($key, $data[$varName])) {
                        $value = $data[$varName][$key];
                        $found = true;
                    }
                } else {
                    // Simple variable
                    if (isset($data[$varName])) {
                        $value = $data[$varName];
                        $found = true;
                    }
                }

                // If variable wasn't found, don't show content
                if (!$found) {
                    return '';
                }

                // Show content if value is truthy (not empty, not null, not false)
                // Special case: 0 and "0" are considered truthy for display purposes
                if (!empty($value) || $value === '0' || $value === 0) {
                    return $ifContent;
                }

                return ''; // Condition false, return empty
            }, $content);

            return $content;
        }

        /**
         * Process foreach loops in template
         * Supports: <!--foreach($items as $key=>$value)-->...<!--endforeach-->
         * Also supports: <!--foreach($items as $item)-->...<!--endforeach-->
         */
        function processForeachLoops($content, $data)
        {
            // Pattern to match foreach loops with key=>value
            $pattern = '/<!--\s*foreach\s*\(\s*\$(\w+)\s+as\s+\$(\w+)\s*=>\s*\$(\w+)\s*\)\s*-->(.*?)<!--\s*endforeach\s*-->/is';

            $content = preg_replace_callback($pattern, function ($matches) use ($data) {
                $arrayName = $matches[1];    // e.g., "items"
                $keyVar = $matches[2];       // e.g., "key"
                $valueVar = $matches[3];     // e.g., "value"
                $loopContent = $matches[4];  // Content inside the loop

                // Check if array exists in data
                if (!isset($data[$arrayName]) || !is_array($data[$arrayName])) {
                    return ''; // Array not found, return empty
                }

                $output = '';
                foreach ($data[$arrayName] as $key => $value) {
                    $loopData = $data; // Start with all data
                    $loopData[$keyVar] = $key;
                    $loopData[$valueVar] = $value;

                    // Process this iteration
                    $iterationContent = $loopContent;

                    // Process includes with loop data context
                    $iterationContent = processIncludesInLoop($iterationContent, $loopData);

                    // Process if statements
                    $iterationContent = processIfStatements($iterationContent, $loopData);

                    // Process variables
                    $iterationContent = processVariables($iterationContent, $loopData);

                    $output .= $iterationContent;
                }

                return $output;
            }, $content);

            // Pattern to match foreach loops without key (just value)
            $pattern = '/<!--\s*foreach\s*\(\s*\$(\w+)\s+as\s+\$(\w+)\s*\)\s*-->(.*?)<!--\s*endforeach\s*-->/is';

            $content = preg_replace_callback($pattern, function ($matches) use ($data) {
                $arrayName = $matches[1];    // e.g., "items"
                $valueVar = $matches[2];     // e.g., "item"
                $loopContent = $matches[3];  // Content inside the loop

                // Check if array exists in data
                if (!isset($data[$arrayName]) || !is_array($data[$arrayName])) {
                    return ''; // Array not found, return empty
                }

                $output = '';
                foreach ($data[$arrayName] as $value) {
                    $loopData = $data; // Start with all data
                    $loopData[$valueVar] = $value;

                    // Process this iteration
                    $iterationContent = $loopContent;

                    // Process includes with loop data context
                    $iterationContent = processIncludesInLoop($iterationContent, $loopData);

                    // Process if statements
                    $iterationContent = processIfStatements($iterationContent, $loopData);

                    // Process variables
                    $iterationContent = processVariables($iterationContent, $loopData);

                    $output .= $iterationContent;
                }

                return $output;
            }, $content);

            return $content;
        }

        /**
         * Process simple variables in template
         * Supports: <!--$variable--> and <!--$array[key]--> and <!--$array["key"]-->
         */
        function processVariables($content, $data)
        {
            // Pattern to match array access: <!--$variable[key]--> or <!--$variable["key"]-->
            $content = preg_replace_callback('/<!--\s*\$(\w+)\[(["\']?)([^\]]+)\2\]\s*-->/i', function ($matches) use ($data) {
                $varName = $matches[1];
                $key = $matches[3]; // Key without quotes

                if (isset($data[$varName]) && is_array($data[$varName]) && isset($data[$varName][$key])) {
                    return htmlspecialchars($data[$varName][$key], ENT_QUOTES, 'UTF-8');
                }

                return ''; // Variable or key not found
            }, $content);

            // Pattern to match simple variables: <!--$variable-->
            $content = preg_replace_callback('/<!--\s*\$(\w+)\s*-->/i', function ($matches) use ($data) {
                $varName = $matches[1];

                if (isset($data[$varName])) {
                    $value = $data[$varName];

                    // If it's a scalar value, return it
                    if (is_scalar($value)) {
                        return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
                    }

                    // If it's an array or object, return JSON representation
                    if (is_array($value) || is_object($value)) {
                        return htmlspecialchars(json_encode($value), ENT_QUOTES, 'UTF-8');
                    }
                }

                return ''; // Variable not found
            }, $content);

            return $content;
        }

        /**
         * Parse include parameters from string
         * Supports: 'key'=>'value','key2'=>'value2'
         * Supports: 'key'=>123 (numeric values without quotes)
         */
        function parseIncludeParams($paramsString)
        {
            $params = [];

            // Pattern to match key=>value pairs with support for:
            // 1. Quoted values: 'key'=>'value' or "key"=>"value"
            // 2. Numeric values: 'key'=>123 or 'key'=>45.67
            preg_match_all('/[\'"]([^\'"]+)[\'"]\s*=>\s*(?:[\'"]([^\'"]*)[\'"]|([\d.]+))/', $paramsString, $matches, PREG_SET_ORDER);

            foreach ($matches as $match) {
                $key = $match[1];
                // Check if it's a quoted value (group 2) or numeric value (group 3)
                if (isset($match[3]) && $match[3] !== '') {
                    // Numeric value without quotes
                    $value = $match[3];
                    // Convert to int or float as appropriate
                    $params[$key] = strpos($value, '.') !== false ? (float) $value : (int) $value;
                } else {
                    // Quoted string value
                    $value = $match[2];
                    $params[$key] = $value;
                }
            }

            return $params;
        }

        /**
         * Process include directives in HTML content
         * Supports: <!--include:path/to/file.html-->
         * Supports: <!--include:path/to/file.html ['key'=>'value','key2'=>'value2']-->
         * Top-level includes without data context
         */
        function processIncludes($content, $baseDir, $data = [])
        {
            // Pattern to match include comments with optional parameters
            // Matches: <!--include:path--> or <!--include:path ['key'=>'value']-->
            $pattern = '/<!--\s*include:\s*([^\s\[]+)(?:\s*\[([^\]]+)\])?\s*-->/i';

            // Keep processing until no more includes are found (supports nested includes)
            $maxDepth = 10; // Prevent infinite loops
            $depth = 0;

            while (preg_match($pattern, $content) && $depth < $maxDepth) {
                $content = preg_replace_callback($pattern, function ($matches) use ($baseDir, $data) {
                    $includePath = trim($matches[1]);
                    $paramsString = isset($matches[2]) ? trim($matches[2]) : '';

                    // Parse parameters if provided
                    $includeParams = [];
                    if (!empty($paramsString)) {
                        $includeParams = parseIncludeParams($paramsString);
                    }

                    // Merge params with action data (action data overwrites include params - include params are defaults)
                    $mergedData = array_merge($includeParams, $data);

                    // Build the full path
                    // If path starts with /, treat as absolute from views directory
                    if (strpos($includePath, '/') === 0) {
                        // Absolute path from views directory
                        $viewsDir = dirname(dirname(__FILE__)) . '/views';
                        $fullPath = $viewsDir . $includePath;
                    } else {
                        // Relative path from current directory
                        $fullPath = $baseDir . '/' . $includePath;
                    }
                    // Check if file exists
                    if (file_exists($fullPath) && is_file($fullPath)) {
                        // Read the included file content
                        $includedContent = file_get_contents($fullPath);

                        // Process variables in the included content if data is provided
                        if (!empty($mergedData)) {
                            $includedContent = processIfStatements($includedContent, $mergedData);
                            $includedContent = processVariables($includedContent, $mergedData);
                        }

                        return $includedContent;
                    } else {
                        // Return a comment indicating the file was not found
                        return "<!-- Include not found: {$includePath} -->";
                    }
                }, $content);

                $depth++;
            }

            return $content;
        }

        /**
         * Process include directives within foreach loops
         * Supports: <!--include:path/to/file.html--> or <!--include('path/to/file.html')-->
         * Supports: <!--include:path/to/file.html ['key'=>'value']-->
         * Loop variables are available in the included file
         */
        function processIncludesInLoop($content, $loopData)
        {
            // Pattern to match include with optional parameters: <!--include:path ['key'=>'value']-->
            $pattern = '/<!--\s*include:\s*([^\s\[]+)(?:\s*\[([^\]]+)\])?\s*-->/i';

            $content = preg_replace_callback($pattern, function ($matches) use ($loopData) {
                $includePath = trim($matches[1]);
                $paramsString = isset($matches[2]) ? trim($matches[2]) : '';

                // Parse parameters if provided
                $includeParams = [];
                if (!empty($paramsString)) {
                    $includeParams = parseIncludeParams($paramsString);
                }

                // Merge params with loop data (loop data overwrites include params - include params are defaults)
                $mergedData = array_merge($includeParams, $loopData);

                // Get the content directory path
                $contentDir = dirname(dirname(__FILE__)) . '/views';

                // Build the full path
                // If path starts with /, it's already absolute from views directory
                if (strpos($includePath, '/') === 0) {
                    // Absolute path from views directory
                    $fullPath = $contentDir . $includePath;
                } else {
                    // Relative path from views directory
                    $fullPath = $contentDir . '/' . $includePath;
                }

                // Check if file exists
                if (file_exists($fullPath) && is_file($fullPath)) {
                    // Read the included file content
                    $includedContent = file_get_contents($fullPath);

                    // Process the included content with merged data
                    // Process if statements first
                    $includedContent = processIfStatements($includedContent, $mergedData);

                    // Then process variables
                    $includedContent = processVariables($includedContent, $mergedData);

                    return $includedContent;
                } else {
                    // Return a comment indicating the file was not found
                    return "<!-- Include not found: {$includePath} -->";
                }
            }, $content);

            return $content;
        }

        /**
         * Serve 404 error page
         */
        function serve404($contentDir)
        {
            $notFoundPath = $contentDir . '/404.html';

            http_response_code(404);
            header('Content-Type: text/html; charset=UTF-8');

            if (file_exists($notFoundPath) && is_file($notFoundPath)) {
                readfile($notFoundPath);
            } else {
                // Fallback 404 message if 404.html doesn't exist
                echo '<!DOCTYPE html>
                <html lang="en">
                <head><meta charset="UTF-8">
                    <meta name="viewport" content="width=device-width, initial-scale=1.0">
                    <title>404 - Page Not Found</title>
                    <style>
                        body {
                            font-family: Arial, sans-serif;
                            display: flex;
                            justify-content: center;
                            align-items: center;
                            height: 100vh;
                            margin: 0;
                            background-color: #f5f5f5;
                        }
                        .error-container {
                            text-align: center;
                            padding: 2rem;
                        }
                        h1 {
                            font-size: 4rem;
                            margin: 0;
                            color: #333;
                        }
                        p {
                            font-size: 1.2rem;
                            color: #666;
                        }
                    </style>
                </head>
                <body>
                    <div class="error-container">
                        <h1>404</h1>
                        <p>Page not found</p>
                    </div>
                </body>
                </html>';
            }
            exit;
        }

        //Start the session
        session_start();

        // Get the requested path
        $requestUri = $_SERVER['REQUEST_URI'];
        $path = parse_url($requestUri, PHP_URL_PATH);

        // Remove leading slash
        $path = ltrim($path, '/');

        // If path is empty, use index
        if (empty($path)) {
            $path = 'index';
        }

        // Define the content directory (relative to this file)
        $contentDir = $projectRoot . '/views';
        $actionsDir = $projectRoot . '/actions';

        // Check if request is for JSON response
        $isJsonRequest = false;
        $basePath = $path;

        if (preg_match('/\.json$/', $path)) {
            $isJsonRequest = true;
            $basePath = preg_replace('/\.json$/', '', $path);
        }

        // Build the file path
        $filePath = $contentDir . '/' . $basePath . '.html';
        $actionFile = $actionsDir . '/' . $basePath . '.php';

        // Check if the file exists
        if (file_exists($filePath) && is_file($filePath)) {
            // Execute action if exists and get returned data
            $actionData = executeAction($basePath, $actionsDir);

            // File found - serve it with action data
            serveFile($filePath, $actionData);
        } else if (file_exists($actionFile) && is_file($actionFile)) {
            // No template file but action exists - execute action only
            // This allows for API endpoints without templates
            $actionData = executeAction($basePath, $actionsDir);

            // Return JSON response
            http_response_code(200);
            header('Content-Type: application/json');
            echo json_encode($actionData);
            exit;
        } else {
            // No matching file or action - serve 404
            serve404($contentDir);
        }
    }
}

/**
 * Helper functions. This function should be used in the actions
 */

/**
 * Generate or retrieve CSRF token from session
 */
if (!function_exists('getCsrfToken')) {
    function getCsrfToken() 
    {
        if (!isset($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        }
        return $_SESSION['csrf_token'];
    }
}

/**
 * Process CSRF token directives in HTML content
 * Supports: <!--csrf-->
 */
if (!function_exists('processCsrfTokens')) {
    function processCsrfTokens($content)
    {
        $token = getCsrfToken();
        $csrfField = '<input type="hidden" name="csrf_token" value="' . htmlspecialchars($token, ENT_QUOTES, 'UTF-8') . '">';

        // Replace all <!--csrf--> comments with the CSRF input field
        $content = preg_replace('/<!--\s*csrf\s*-->/i', $csrfField, $content);

        return $content;
    }
}

/**
 * Process date shortcode directives in HTML content
 * Supports: <!--date--> (default format: Y-m-d H:i:s)
 * Supports: <!--date Y--> (custom format: just year)
 * Supports: <!--date d/m/Y--> (custom format: day/month/year)
 * Uses PHP date() format parameters
 */
if (!function_exists('processDateShortcodes')) {
    function processDateShortcodes($content)
    {
        // Pattern to match <!--date format--> with optional format parameter
        $content = preg_replace_callback('/<!--\s*date(?:\s+([^-]+?))?\s*-->/i', function($matches) {
            // Default format if none specified
            $format = isset($matches[1]) && !empty(trim($matches[1])) ? trim($matches[1]) : 'Y-m-d H:i:s';
            
            // Return formatted date
            return date($format);
        }, $content);

        return $content;
    }
}

/**
 * Process flash message directives in HTML content
 * Supports: <!--flush--> (displays all flash messages wrapped in divs with class based on key)
 * Supports: <!--flush:key--> (displays specific flash message by key wrapped in div)
 */
if (!function_exists('processFlashMessages')) {
    function processFlashMessages($content)
    {
        // Pattern to match <!--flush:key--> for specific flash messages
        $content = preg_replace_callback('/<!--\s*flush:\s*(\w+)\s*-->/i', function ($matches) {
            $key = $matches[1];
            $message = getFlash($key);

            if ($message !== null) {
                $escapedMessage = htmlspecialchars($message, ENT_QUOTES, 'UTF-8');
                $escapedKey = htmlspecialchars($key, ENT_QUOTES, 'UTF-8');
                return '<div class="flash-' . $escapedKey . '">' . $escapedMessage . '</div>';
            }

            return '';
        }, $content);

        // Pattern to match <!--flush--> for all flash messages
        $content = preg_replace_callback('/<!--\s*flush\s*-->/i', function ($matches) {
            if (!isset($_SESSION['flash']) || empty($_SESSION['flash'])) {
                return '';
            }

            $output = '';
            foreach ($_SESSION['flash'] as $key => $message) {
                $escapedMessage = htmlspecialchars($message, ENT_QUOTES, 'UTF-8');
                $escapedKey = htmlspecialchars($key, ENT_QUOTES, 'UTF-8');
                $output .= '<div class="flash-' . $escapedKey . '">' . $escapedMessage . '</div>';
            }

            // Clear all flash messages after displaying
            $_SESSION['flash'] = [];

            return $output;
        }, $content);

        return $content;
    }
}

/**
 * Helper function to verify CSRF token
 * Verify CSRF token from POST request
 * Call this function in your form processing code
 */
if (!function_exists('verifyCsrf')) {
    function verifyCsrf()
    {
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $token = $_POST['csrf_token'] ?? '';
            $sessionToken = $_SESSION['csrf_token'] ?? '';

            if (!hash_equals($sessionToken, $token)) {
                http_response_code(403);
                die('CSRF token validation failed');
            }
        }
    }
}

/**
 * Helper function to redirect
 * Redirect to another page
 */
if (!function_exists('redirect')) {
    function redirect($url, $statusCode = 302)
    {
        header("Location: $url", true, $statusCode);
        exit;
    }
}
/**
 * Helper function to print json data
 * @param mixed $data
 * @param mixed $statusCode
 * @return never
 */
if (!function_exists('json')) {
    function json($data = [], $statusCode = 200)
    {
        http_response_code($statusCode);
        header('Content-Type: application/json');
        echo json_encode($data);
        exit;
    }
}

/**
 * Set flash message in session
 */
if (!function_exists('setFlash')) {
    function setFlash($key, $value)
    {
        if (!isset($_SESSION['flash'])) {
            $_SESSION['flash'] = [];
        }
        $_SESSION['flash'][$key] = $value;
    }
}

/**
 * Get and clear flash message from session
 */
if (!function_exists('getFlash')) {
    function getFlash($key, $default = null)
    {
        if (!isset($_SESSION['flash'][$key])) {
            return $default;
        }
        $value = $_SESSION['flash'][$key];
        unset($_SESSION['flash'][$key]);
        return $value;
    }
}

/**
 * Get POST data safely
 */
if (!function_exists('getPost')) {
    function getPost($key, $default = null)
    {
        $value = $_POST[$key] ?? $default;
        return htmlspecialchars(strip_tags($value), ENT_QUOTES, 'UTF-8');
    }
}

/**
 * Get GET data safely
 */
if (!function_exists('getQuery')) {
    function getQuery($key, $default = null)
    {
        $value = $_GET[$key] ?? $default;
        return htmlspecialchars(strip_tags($value), ENT_QUOTES, 'UTF-8');
    }
}