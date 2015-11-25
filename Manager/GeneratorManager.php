<?php

/**
 * Created by JetBrains PhpStorm.
 * User: bernard.bernard
 * Date: 04/11/15
 * Time: 00:30
 * To change this template use File | Settings | File Templates.
 */
namespace Enneite\SwaggerBundle\Manager;

use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Helper\TableSeparator;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\Yaml\Parser;

class GeneratorManager
{
    private $container;

    private $namespace;
    private $outputPath;
    private $routingType;
    private $swaggerFilePath;
    private $bundleName;
    private $routingPrefix;

    /**
     * @return mixed
     */
    public function getNamespace()
    {
        return $this->namespace;
    }

    /**
     * @return mixed
     */
    public function getOutputPath()
    {
        return $this->outputPath;
    }

    /**
     * @return mixed
     */
    public function getRoutingType()
    {
        return $this->routingType;
    }

    /**
     * @return mixed
     */
    public function getSwaggerFilePath()
    {
        return $this->swaggerFilePath;
    }

    /**
     * @return mixed
     */
    public function getRoutingPrefix()
    {
        return $this->routingPrefix;
    }

    /**
     * @param ContainerInterface $container
     */
    public function __construct(ContainerInterface $container)
    {
        $this->container = $container;
    }

    /**
     * @param $swaggerConf
     *
     * @return $this
     *
     * @throws \Exception
     */
    public function loadConfig($swaggerConf)
    {
        $this->namespace = ucfirst($swaggerConf['destination_namespace']);
        $this->bundleName = ucfirst($swaggerConf['name']).'Bundle';
        $this->routingType = $swaggerConf['routing_type'];
        $this->routingPrefix = (isset($swaggerConf['routing_prefix'])) ? '/'.$swaggerConf['routing_prefix'] : '';
        $this->swaggerFilePath = $swaggerConf['config_file'];
        $this->outputPath = realpath($this->container->get('kernel')->getRootDir().'/../src/').'/'.str_replace('\\', '/', $this->namespace).'/'.$this->bundleName.'/';

        return $this;
    }

    /**
     * @return mixed
     *
     * @throws \Exception
     */
    public function getSwaggerConfig()
    {
        $swaggerFilePath = $this->swaggerFilePath;

        // @todo : refactoriser pour eviter la repetition
        if ($swaggerFilePath != null) {
            if (file_exists($swaggerFilePath)) {
                if (preg_match("/\.(yaml|yml)$/i", $swaggerFilePath)) {
                    $yaml = new Parser();

                    return $yaml->parse(file_get_contents($swaggerFilePath));
                } else {
                    return json_decode(file_get_contents($swaggerFilePath), true);
                }
            }
        } else {
            $swaggerFilePath = $this->container->get('kernel')->getRootDir().'/config/swagger';
            if (file_exists($swaggerFilePath.'.json')) {
                return json_decode(file_get_contents($swaggerFilePath.'.json'), true);
            }
            if (file_exists($swaggerFilePath.'.yml')) {
                $yaml = new Parser();

                return $yaml->parse(file_get_contents($swaggerFilePath.'.yml'));
            }
        }

        throw new \Exception('Swagger file not found!');
    }

    /**
     * @param $output
     */
    public function generateBundle($output)
    {
        $generator = $this->container->get('enneite_swagger.bundle_generator');
        $result = $generator->generate($this->outputPath, $this->namespace, $this->bundleName);
        $message = ' - '.$this->namespace.$this->bundleName;
        $message .= $result == 1 ? ' ( add to kernel )' : ' ( already in kernel )';
        $output->writeln('<info>'.$message.'</info>');
    }

    /**
     * create the controller class name.
     *
     * @param $pathName
     *
     * @return string
     */
    private function getClassName($pathName)
    {
        $className = preg_replace('/[^a-zA-Z0-9]+/', '/', $pathName);

        return str_replace(' ', '', ucwords(str_replace('/', ' ', $className)));
    }

    /**
     * @param $paths
     * @param $output
     */
    public function generateControllers($paths, $output)
    {
        $annotation = ('annotation' === $this->getRoutingType());
        $generator = $this->container->get('enneite_swagger.controller_generator');

        $namespace = $this->namespace.'\\'.$this->bundleName;
        $rows = array();
        foreach ($paths as $pathName => $pathArray) {
            $generator->generate($this->outputPath, $namespace, $this->getClassName($pathName), $pathName, $pathArray, $annotation);
            if ($output->isVerbose()) {
                $rows[] = array($generator->getNamespace(), $generator->getClassName().'.php');
            }
        }

        if ($output->isVerbose()) {
            $table = new Table($output);
            $table->setHeaders(array('Namespace', 'File'))->setRows($rows)->render();
        }
        $output->writeln('<info>RESUME : '.count($paths).' controllers generated</info>');
    }

    /**
     * @param $paths
     * @param $output
     */
    public function generateYamlRouter($paths, $output)
    {
        $outputStr = '';
        $generator = $this->container->get('enneite_swagger.router_generator');

        $rows = array();
        foreach ($paths as $pathName => $path) {
            $className = $this->getClassName($pathName);
            foreach ($path as $verb => $objects) {
                $outputStr .= $generator->createYamlConf($verb, $objects, $pathName, $this->namespace.$this->bundleName, $className);
                if ($output->isVerbose()) {
                    $rows[] = array($this->routingPrefix.$pathName, $verb);
                }
            }
            if ($output->isVerbose()) {
                $rows[] = new TableSeparator();
            }
        }

        $outputFile = $this->outputPath.'Resources/config/routing.yml';
        $result = $generator->generate($outputStr, $outputFile, $this->namespace.$this->bundleName, $this->routingPrefix);

        if ($output->isVerbose()) {
            $table = new Table($output);
            array_pop($rows);
            $table->setHeaders(array('Path', 'Method'))->setRows($rows)->render();
        }
        $message = ' - Resources/config/routing.yml created';
        $message .= $result == 1 ? ' ( add to app )' : ' ( already in app )';
        $output->writeln('<info>'.$message.'</info>');
    }

    /**
     * @param $definitions
     * @param $output
     */
    public function generateEntities($definitions, $output)
    {
        $generator = $this->container->get('enneite_swagger.entity_generator');

        $namespace = $this->namespace.'\\'.$this->bundleName;
        foreach ($definitions as $entityName => $entity) {
            $generator->generate($this->outputPath, $namespace, $entityName, $entity);
            if ($output->isVerbose()) {
                $rows[] = array($generator->getNamespace(), $generator->getClassName().'.php');
            }
        }

        if ($output->isVerbose()) {
            $table = new Table($output);
            $table->setHeaders(array('Namespace', 'File'))->setRows($rows)->render();
        }
        $output->writeln('<info>RESUME : '.count($definitions).' entities generated</info>');
    }
}
