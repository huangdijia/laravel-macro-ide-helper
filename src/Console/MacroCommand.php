<?php

namespace Huangdijia\IdeHelper\Console;

use Illuminate\Support\Str;
use Illuminate\Console\Command;
use Barryvdh\Reflection\DocBlock;
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

        $classMaps      = include $classMapFile;
        $helperContents = [];

        $namespaces = config('macro-ide-helper.namespaces', [
            'App\\',
            'Illuminate\\',
        ]);

        collect($classMaps)
            ->filter(function ($path, $class) use ($namespaces) {
                return Str::startsWith($class, $namespaces);
            })
            ->reject(function ($path, $class) {
                $rejects = config('macro-ide-helper.rejects', [
                    'Illuminate\\Filesystem\\Cache',
                ]);
                return in_array($class, $rejects);
            })
            ->each(function ($path, $class) use (&$helperContents) {
                try {
                    $reflection = new \ReflectionClass($class);
                    $traits     = array_keys($reflection->getTraits() ?? []);

                    if (
                        empty($traits)
                        || !in_array(Macroable::class, $traits)
                    ) {
                        return;
                    }

                    $namespace = $reflection->getNamespaceName();
                    $shortName = $reflection->getShortName();
                    $property = $reflection->getProperty('macros');
                    $property->setAccessible(true);
                    $macros = $property->getValue(null);

                    if (empty($macros)) {
                        return;
                    }

                    $phpDoc = new DocBlock($reflection, new DocBlock\Context($reflection->getNamespaceName()));
                    $phpDoc->setText($class);

                    foreach ($macros as $macroName => $macroCallback) {
                        $macro = new \ReflectionFunction($macroCallback);
                        // $params = array_map(function (\ReflectionParameter $parameter) {
                        //     return $this->prepareParameter($parameter);
                        // }, $macro->getParameters());
                        $params     = join(', ', array_map([$this, 'prepareParameter'], $macro->getParameters()));
                        $doc        = $macro->getDocComment();
                        $returnType = $doc && preg_match('/@return ([a-zA-Z\[\]\|\\\]+)/', $doc, $matches) ? $matches[1] : '';
                        $tag        = DocBlock\Tag::createInstance("@method {$returnType} {$macroName}({$params})", $phpDoc);

                        $phpDoc->appendTag($tag);
                    }

                    $phpDoc->appendTag(DocBlock\Tag::createInstance('@package macro_ide_helper'));

                    $serializer = new DocBlock\Serializer;
                    $docComment = $serializer->getDocComment($phpDoc);

                    $helperContents[$namespace][] = [
                        'shortName'  => $shortName,
                        'docComment' => $docComment,
                    ];

                } catch (\Throwable $e) {
                    $this->error($e->getMessage());
                    return;
                }
            });

        $contents   = [];
        $contents[] = "<?php";
        $contents[] = "// @formatter:off";
        $contents[] = '';

        foreach ($helperContents as $namespace => $classes) {
            $contents[] = "namespace {$namespace} {";
            $contents[] = '';

            foreach ($classes as $class) {
                $contents[] = $class['docComment'];
                $contents[] = "    class {$class['shortName']}";
                $contents[] = "    {";
                $contents[] = "    }";
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
                $defaultValue = is_array($defaultValue) ? join(', ', $defaultValue) : $defaultValue;
                $parameterString .= " = {$defaultValue}";
            }
        }

        return $parameterString;
    }
}
