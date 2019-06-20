<?php

namespace Huangdijia\IdeHelper\Console;

use Barryvdh\Reflection\DocBlock;
use Illuminate\Console\Command;
use Illuminate\Support\Str;
use Illuminate\Support\Traits\Macroable;

class MacroCommand extends Command
{
    protected $name        = 'ide-helper:macros';
    protected $description = 'Generate a new Macros IDE Helper file.';

    public function handle()
    {
        $classMapFile = app()->basePath('vendor/composer/autoload_classmap.php');

        if (!file_exists($classMapFile)) {
            $this->error("{$classMapFile} is not found");
            return;
        }

        $classMaps  = include $classMapFile;
        $namespaces = config('macro-ide-helper.namespaces');

        $docs = collect($classMaps)
            ->filter(function ($path, $class) use ($namespaces) {
                return Str::startsWith($class, $namespaces);
            })
            ->reject(function ($path, $class) {
                $rejects = config('macro-ide-helper.rejects', []);
                return in_array($class, $rejects);
            })
            ->mapWithKeys(function ($path, $class) {
                try {
                    $reflection = new \ReflectionClass($class);
                    $traits     = array_keys($reflection->getTraits() ?? []);

                    if (
                        empty($traits)
                        || !in_array(Macroable::class, $traits)
                    ) {
                        return [];
                    }

                    return [$class => $reflection];
                } catch (\Exception $e) {
                    $this->error($e->getMessage());
                    return [];
                }
            })
            ->filter()
            ->mapToGroups(function ($reflection, $class) {
                try {
                    $namespace = $reflection->getNamespaceName();
                    $shortName = $reflection->getShortName();

                    $property = $reflection->getProperty('macros');
                    $property->setAccessible(true);
                    $macros = $property->getValue(null);

                    if (empty($macros)) {
                        return [];
                    }

                    $phpDoc = new DocBlock($reflection, new DocBlock\Context($namespace));
                    $phpDoc->setText($class);

                    foreach ($macros as $name => $closure) {
                        $macro = new \ReflectionFunction($closure);

                        $params     = join(', ', array_map([$this, 'prepareParameter'], $macro->getParameters()));
                        $doc        = $macro->getDocComment();
                        $returnType = $doc && preg_match('/@return ([a-zA-Z\[\]\|\\\]+)/', $doc, $matches) ? $matches[1] : '';
                        $phpDoc->appendTag(DocBlock\Tag::createInstance("@method {$returnType} {$name}({$params})"));

                        $see = $macro->getClosureScopeClass()->getName();
                        $phpDoc->appendTag(DocBlock\Tag::createInstance("@see \\{$see}", $phpDoc));

                        $sourceFile = Str::replaceFirst(base_path() . '/', '', $macro->getFileName());
                        $startLine  = $macro->getStartLine();
                        $endLine    = $macro->getEndLine();
                        $phpDoc->appendTag(DocBlock\Tag::createInstance("@see {$sourceFile} {$startLine} {$endLine}", $phpDoc));
                    }

                    $phpDoc->appendTag(DocBlock\Tag::createInstance('@package macro_ide_helper'));

                    $serializer = new DocBlock\Serializer;
                    $docComment = $serializer->getDocComment($phpDoc);

                    return [
                        $namespace => [
                            'shortName'  => $shortName,
                            'docComment' => $docComment,
                        ],
                    ];

                } catch (\Throwable $e) {
                    $this->error($e->getMessage());
                    return [];
                }
            })
            ->reject(function ($a, $class) {
                return !$class;
            });

        $contents   = [];
        $contents[] = "<?php";
        $contents[] = "// @formatter:off";

        foreach ($docs as $namespace => $classes) {
            $contents[] = "namespace {$namespace} {";
            $contents[] = '';

            foreach ($classes as $class) {
                $contents[] = $class['docComment'];
                $contents[] = "    class {$class['shortName']} {}";
                $contents[] = '';
            }
            $contents[] = "}";
            $contents[] = '';
        }

        $contents[] = "namespace {}";
        $contents[] = '';

        $filename = base_path(config('macro-ide-helper.filename', '_macro_ide_helper.php'));
        file_put_contents($filename, join("\n", $contents));

        $this->info("A new helper file was written to {$filename}");
    }

    /**
     * parse parameters
     *
     * @param \ReflectionParameter $parameter
     * @return string
     */
    private function prepareParameter(\ReflectionParameter $parameter): string
    {
        $parameterString = trim(optional($parameter->getType())->getName() . ' $' . $parameter->getName());

        if ($parameter->isOptional()) {
            if ($parameter->isVariadic()) {
                $parameterString = '...' . $parameterString;
            } else {
                $defaultValue = $parameter->isArray() ? '[]' : ($parameter->getDefaultValue() ?? 'null');
                $defaultValue = preg_replace('/\s+/', ' ', var_export($defaultValue, 1));
                $parameterString .= sprintf(" = %s", $defaultValue);
            }
        }

        return $parameterString;
    }
}
