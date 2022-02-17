<?php declare(strict_types=1);

namespace DDT;

use Exception;
use DDT\Exceptions\Autowire\CannotAutowireParameterException;
use ReflectionClass;
use ReflectionMethod;

class Autowire
{
    /** @var callable $resolver A callback to return a type that the autowire class wants resolved to an argument to pass */
    private $resolver;

    public function __construct(callable $resolver)
    {
        $this->resolver = $resolver;
    }

    static public function instantiator(callable $resolver, string $ref, array $args)
    {
        $autowire = new Autowire($resolver);

        // Special case for the autowire class
        if($ref === Autowire::class){
            return $autowire;
        }
        
        return $autowire->getInstance($ref, $args);
    }

    public function getInstance(string $ref, ?array $args=[])
    {
        $rc = new ReflectionClass($ref);
        $rm = $rc->getConstructor();
        $params = $this->getReflectionParameters($rm);
        
        $args = $this->reformatArgs($args);
        $args = $this->resolveArgs($params, $args);

        return $rc->newInstanceArgs($args);
    }

    public function callMethod(object $class, string $method, ?array $args=[])
    {
        $rc = new ReflectionClass($class);

        if($rc->hasMethod($method) === true || $rc->hasMethod('__call') === false){
            $rm = $rc->getMethod($method);
            $params = $this->getReflectionParameters($rm);

            $args = $this->reformatArgs($args);
            $args = $this->resolveArgs($params, $args);

            return $rm->invoke($class, ...$args);
        }

        // TODO: how will this reformat/resolve argument code work 
        // against __call interfaces which typically have no arguments
        // and work like magic? Surely this will fail?
        $rm = $rc->getMethod('__call');

        return $rm->invoke($class, $method, $args);
    }

    private function getReflectionParameters(ReflectionMethod $method): array
    {
        $params = $method->getParameters();
            
        // Resolve parameter data to a simple array
        $params = array_map(function($p) {
            $temp = ['name' => $p->getName(), 'type' => $p->getType()];
            if($p->isOptional()){
                $temp['default'] = $p->getDefaultValue();
            }
            return $temp;
        }, $params);

        return $params;
    }

    private function reformatArgs($inputParameters): array
    {
        // Don't do anything if the array if empty
        if(count($inputParameters) === 0) return $inputParameters;

        $output = [];

        debugVar(["REFORMAT ARGS" => $inputParameters]);

        foreach($inputParameters as $name => $arg){
            if(is_string($name)){
                // Take care of string key-ed arrays
                $output[] = ['name' => trim($name, " -"), 'value' => $arg];
            }else if(is_int($name) && is_array($arg)){
                // Take care of numerically indexed arrays with array like data (such as command line arguments)
                if(array_key_exists('name', $arg)){
                    $arg['name'] = trim($arg['name'], " -");
                    $output[] = $arg;
                }else{
                    // I'm not sure what other formats to take care of right now
                }
            }
        }
        debugVar(["FINAL OUTPUT" => $output]);

        return $output;
    }

    private function resolveArgs(array $signatureParameters, array $inputParameters): array
    {
        $output = [];

        debugVar("STARTING AUTOWIRING....................");
        debugVar(["INPUT WAS" => $inputParameters]);
        foreach($signatureParameters as $search){
            $name = $search['name'];
            $type = trim((string)$search['type'], '?');

            if(empty($type)){
                $type = 'string';
            }

            debugVar("=======================\nSEARCH PARAMETER: name = '$name' with type '$type'");

            if(class_exists($type) || interface_exists($type)){
                // When the type is a class, 
                foreach($inputParameters as $index => $data){
                    if($data['name']  === $name && is_object($data['value']) && get_class($data['value']) === $type){
                        debugVar("FOUND OBJECT ARG: name = '$name'");
                        $output[] = $data['value'];
                        unset($inputParameters[$index]);
                        continue 2;
                    }
                }

                debugVar("FOUND CONTAINER ARG: type = '$type'");
                $output[] = call_user_func($this->resolver, $type);
                continue;
            }else if($type === 'array'){
                foreach($inputParameters as $index => $data){
                    if($data['name'] == $name){
                        if(is_array($data['value'])){
                            debugVar(['FOUND NAMED ARRAY' => $data]);
                            $output[] = $data['value'];
                            unset($inputParameters[$index]);
                            continue 2;
                        }
                    }
                }
            }else{
                // When the type is a string, we look in the input array for matches

                // for every named parameter, we must look for an input parameter with the same name AND HAS A VALUE
                foreach($inputParameters as $index => $data){
                    if($data['name'] === $name){
                        $test_numeric = (int)filter_var($data['value'], FILTER_VALIDATE_INT, FILTER_NULL_ON_FAILURE);
                        debugVar("NAMED TYPE CHECK($name), numeric = $test_numeric, value = '{$data['value']}'");
                        if($type === 'bool'){
                            $value = filter_var($data['value'], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
                            if(is_bool($value)){
                                debugVar("FOUND NAMED BOOL: name = '$name', value = '$value}'");
                                $output[] = $value;
                                unset($inputParameters[$index]);
                                continue 2;
                            }
                        }
                        
                        if($type === 'int'){
                            $value = filter_var($data['name'], FILTER_VALIDATE_INT, FILTER_NULL_ON_FAILURE);
                            if(is_int($value)){
                                debugVar("FOUND NAMED INT: name = '$name', value = '$value'");
                                $output[] = $value;
                                unset($inputParameters[$index]);
                                continue 2;    
                            }
                        }
                        
                        if($type === 'float'){
                            $value = filter_var($data['name'], FILTER_VALIDATE_FLOAT, FILTER_NULL_ON_FAILURE);
                            if(is_float($value)){
                                debugVar("FOUND NAMED FLOAT: name = '$name', value = '$value'");
                                $output[] = $value;
                                unset($inputParameters[$index]);
                                continue 2;    
                            }
                        }
                        
                        if($type === 'string' && array_key_exists('value', $data)){
                            debugVar("FOUND NAMED STRING: name = '$name', value = '{$data['value']}'");
                            $output[] = $data['value'];
                            unset($inputParameters[$index]);
                            continue 2;
                        }
                    }
                }

                // We did not find a named parameter, therefore lets pick the first anonymous parameter
                foreach($inputParameters as $index => $data){
                    if(is_array($data) && array_key_exists('value', $data)){
                        continue;
                    }
                    $test_numeric = (int)filter_var($data['name'], FILTER_VALIDATE_INT, FILTER_NULL_ON_FAILURE);
                    debugVar("ANON TYPE CHECK($type / {$data['name']}), numeric = $test_numeric, value = '{$data['name']}'");
                    if($type === 'bool'){
                        $value = filter_var($data['name'], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
                        if(is_bool($value)) {
                            debugVar("FOUND ANON BOOL: name = '$value'");
                            $output[] = $value;
                            unset($inputParameters[$index]);
                            continue 2;
                        }
                    }

                    if($type === 'int'){
                        $value = filter_var($data['name'], FILTER_VALIDATE_INT, FILTER_NULL_ON_FAILURE);
                        if(is_int($value)){
                            debugVar("FOUND ANON INT: name = '$value'");
                            $output[] = $value;
                            unset($inputParameters[$index]);
                            continue 2;
                        }
                    }

                    if($type === 'float'){
                        $value = filter_var($data['name'], FILTER_VALIDATE_FLOAT, FILTER_NULL_ON_FAILURE);
                        if(is_float($value)){
                            debugVar("FOUND ANON FLOAT: name = '$value'");
                            $output[] = $value;
                            unset($inputParameters[$index]);
                            continue 2;
                        }
                    }

                    if($type === 'string' && !empty($data['name'])){
                        debugVar("FOUND ANON STRING: name = '{$data['name']}'");
                        $output[] = $data['name'];
                        unset($inputParameters[$index]);
                        continue 2;
                    }
                }
            }

            if(array_key_exists('default', $search)){
                debugVar("FOUND DEFAULT VALUE: name = '$name', default = '{$search['default']}'");
                $output[] = $search['default'];
                continue;
            }

            throw new CannotAutowireParameterException($name, $type);
        }

        debugVar(["FINAL OUTPUT" => array_map(function($a){ 
            return is_object($a) ? get_class($a) : $a;
        },$output)]);

        return $output;
    }
}