<?php

/**
 * generate-swagger.php
 *
 * Automatic Swagger/OpenAPI generator by reflection
 * Scans all Resource files and generates OpenAPI 3.0 specification
 *
 * @author     Ludovic Toinel
 * @copyright  2026 Carbure App
 */

use Carbure\Lib\ApiResolver;
use Carbure\Lib\ApiRoute;

require_once __DIR__ . '/../src/autoload.php';

class SwaggerGenerator {

    /** @var array<string, mixed> OpenAPI specification being built */
    private array $spec;
    private string $resourcesPath;

    /**
     * @param string $resourcesPath Directory of the resource classes
     */
    public function __construct(string $resourcesPath) {
        $this->resourcesPath = $resourcesPath;
        $this->spec = [
            'openapi' => '3.0.0',
            'info' => [
                'title' => 'Carbure API',
                'description' => 'Backend API',
                'version' => '1.0.0',
            ],
            // The paths already start with /api: "Try it out" calls /api/... of this server
            'servers' => [
                [
                    'url' => '/',
                    'description' => 'API Server'
                ]
            ],
            'paths' => [],
            'components' => [
                'securitySchemes' => [
                    'BearerAuth' => [
                        'type' => 'http',
                        'scheme' => 'bearer',
                        'bearerFormat' => 'JWT'
                    ]
                ]
            ]
        ];
    }

    /**
     * Scan all resources files and generate OpenAPI spec
     *
     * @return array<string, mixed> The OpenAPI specification
     */
    public function generate(): array {
        $files = glob($this->resourcesPath . '/*.php') ?: [];

        foreach ($files as $file) {
            $this->processResourceFile($file);
        }

        return $this->spec;
    }

    /**
     * Process a single resource file
     *
     * @param string $file Path of the resource file (named after its class)
     * @return void
     */
    private function processResourceFile(string $file): void {
        // Tags and summaries name the class without its namespace (User, not Carbure\Resources\User)
        $resourceName = basename($file, '.php');
        $className = ApiResolver::RESOURCES_NAMESPACE . $resourceName;
        if (!class_exists($className)) {
            return;
        }
        $class = new ReflectionClass($className);

        // Get all public static methods
        $methods = $class->getMethods(ReflectionMethod::IS_PUBLIC | ReflectionMethod::IS_STATIC);

        foreach ($methods as $method) {
            $this->processMethod($resourceName, $method);
        }
    }

    /**
     * Process a method and add it to the OpenAPI spec
     *
     * @param string           $resourceName Short name of the resource class
     * @param ReflectionMethod $method       The method
     * @return void
     */
    private function processMethod(string $resourceName, ReflectionMethod $method): void {
        $methodName = $method->getName();
        
        // Skip private/protected methods
        if (!$method->isPublic() || !$method->isStatic()) {
            return;
        }

        // Check for ApiRoute attribute
        $attributes = $method->getAttributes(ApiRoute::class);
        if (empty($attributes)) {
            return; // Skip methods without ApiRoute annotation
        }

        $apiRoute = $attributes[0]->newInstance();
        
        // Get HTTP method and path from ApiRoute annotation
        // Add /api prefix to all routes
        $httpMethod = strtolower($apiRoute->method);
        $path = '/api' . $apiRoute->path;

        // Get parameters
        $parameters = $this->extractParameters($method, $httpMethod);

        // Get documentation from docblock
        $docComment = $method->getDocComment();
        $description = $this->extractDescription($docComment);

        // Build the operation
        $operation = [
            'summary' => $this->generateSummary($methodName, $resourceName),
            'description' => $description ?: "Endpoint for $resourceName::$methodName",
            'tags' => [$resourceName],
            'responses' => [
                '200' => [
                    'description' => 'Successful response',
                    'content' => [
                        'application/json' => [
                            'schema' => [
                                'type' => 'object'
                            ]
                        ]
                    ]
                ],
                '400' => [
                    'description' => 'Bad request'
                ],
                '401' => [
                    'description' => 'Unauthorized'
                ],
                '500' => [
                    'description' => 'Server error'
                ]
            ]
        ];

        // Add parameters or requestBody based on HTTP method
        $operation = $this->addParameters($operation, $httpMethod, $parameters);

        // Access level of the route: security, description and responses
        $operation = $this->addAccess($operation, $apiRoute);

        // Initialize path if not exists
        if (!isset($this->spec['paths'][$path])) {
            $this->spec['paths'][$path] = [];
        }

        $this->spec['paths'][$path][$httpMethod] = $operation;
    }

    /**
     * Add the access level of the route to an operation: the x-carbure-access extension
     * (public, user or admin), the JWT unless public, and for the administrator routes a
     * mention in the description and the 403 response.
     *
     * @param array<string, mixed> $operation The operation
     * @param ApiRoute             $apiRoute  The route
     * @return array<string, mixed> The operation with its access level
     */
    private function addAccess(array $operation, ApiRoute $apiRoute): array {
        $operation['x-carbure-access'] = $apiRoute->access();

        // Not public = requires a JWT
        if (!$apiRoute->public) {
            $operation['security'] = [
                ['BearerAuth' => []]
            ];
        }

        if ($apiRoute->admin) {
            $operation['description'] .= ' (Administrators only.)';
            $operation['responses']['403'] = ['description' => 'Forbidden - Administrator only'];
            ksort($operation['responses']);
        }
        return $operation;
    }

    /**
     * Add the parameters to an operation: in the query string (GET, DELETE) or as the
     * properties of the JSON request body (POST, PUT, PATCH).
     *
     * @param array<string, mixed>                    $operation  The operation
     * @param string                                  $httpMethod HTTP method, lowercase
     * @param array<int|string, array<string, mixed>> $parameters The parameters (see extractParameters)
     * @return array<string, mixed> The operation with its parameters
     */
    private function addParameters(array $operation, string $httpMethod, array $parameters): array {
        if (empty($parameters)) {
            return $operation;
        }
        if ($httpMethod === 'get' || $httpMethod === 'delete') {
            $operation['parameters'] = $parameters;
            return $operation;
        }
        // POST, PUT, PATCH: use requestBody
        $operation['requestBody'] = [
            'required' => true,
            'content' => [
                'application/json' => [
                    'schema' => [
                        'type' => 'object',
                        'properties' => $parameters,
                        'required' => array_keys(array_filter($parameters, function($p) {
                            return $p['required'] ?? false;
                        }))
                    ]
                ]
            ]
        ];
        return $operation;
    }



    /**
     * Extract parameters from method signature
     *
     * @param ReflectionMethod $method     The method
     * @param string           $httpMethod HTTP method, lowercase
     * @return array<int|string, array<string, mixed>> Query parameters (get, delete), or the
     *         properties of the request body by name
     */
    private function extractParameters(ReflectionMethod $method, string $httpMethod): array {
        $parameters = [];
        
        foreach ($method->getParameters() as $param) {
            $paramName = $param->getName();
            
            $type = $this->parameterType($param);
            $schema = $type === null ? new stdClass() : ['type' => $type];

            // Check if parameter has default value
            $required = !$param->isOptional();

            if ($httpMethod === 'get' || $httpMethod === 'delete') {
                // GET/DELETE: parameters in query string
                $parameters[] = [
                    'name' => $paramName,
                    'in' => 'query',
                    'required' => $required,
                    'schema' => $schema,
                    'description' => ucfirst($paramName) . ' parameter'
                ];
            } else {
                // POST/PUT/PATCH: parameters in request body
                $parameters[$paramName] = ($type === null ? [] : ['type' => $type]) + [
                    'description' => ucfirst($paramName) . ' parameter',
                    'required' => $required
                ];
            }
        }

        return $parameters;
    }

    /**
     * OpenAPI type of a parameter (string by default); a union type (int|string...) is
     * described by its string type if any, else its first type; mixed by no type.
     *
     * @param ReflectionParameter $param The parameter
     * @return string|null The OpenAPI type, null for any value
     */
    private function parameterType(ReflectionParameter $param): ?string {
        $named = $param->getType();
        if ($named instanceof ReflectionUnionType) {
            // A string is what the query string gives: preferred when the union accepts it
            $types = $named->getTypes();
            $strings = array_filter($types, fn($t) => $t instanceof ReflectionNamedType && $t->getName() === 'string');
            $named = $strings ? reset($strings) : $types[0];
        }
        if (!$named instanceof ReflectionNamedType) {
            return 'string';
        }
        return match ($named->getName()) {
            'int' => 'integer',
            'float' => 'number',
            'bool', 'true', 'false' => 'boolean',
            'array' => 'object',
            'mixed' => null,
            default => 'string',
        };
    }

    /**
     * Extract description from docblock
     *
     * @param string|false $docComment The docblock, false if none
     * @return string The description
     */
    private function extractDescription(string|false $docComment): string {
        if (!$docComment) {
            return '';
        }

        // Extract lines between /** and */
        $lines = explode("\n", $docComment);
        $description = [];

        foreach ($lines as $line) {
            $line = trim($line);
            // Remove /** */ and *
            $line = preg_replace('/^\/?\*+\/?\s*/', '', $line);
            
            // Stop at @param or other tags
            if (strpos($line, '@') === 0) {
                break;
            }

            if (!empty($line)) {
                $description[] = $line;
            }
        }

        return implode(' ', $description);
    }

    /**
     * Generate a human-readable summary
     *
     * @param string $methodName   Name of the method
     * @param string $resourceName Name of the resource class
     * @return string The summary
     */
    private function generateSummary(string $methodName, string $resourceName): string {
        // Convert camelCase to readable text
        $summary = preg_replace('/([a-z])([A-Z])/', '$1 $2', $methodName);
        $summary = ucfirst($summary);
        
        return "$summary $resourceName";
    }

    /**
     * Output the OpenAPI spec as JSON
     *
     * @return void
     */
    public function output(): void {
        header('Content-Type: application/json');
        echo json_encode($this->spec, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    }

    /**
     * Get the spec array
     *
     * @return array<string, mixed> The OpenAPI specification
     */
    public function getSpec(): array {
        return $this->spec;
    }
}

// Generate the swagger specification
$generator = new SwaggerGenerator(__DIR__ . '/../src/resources');
$generator->generate();
$generator->output();
