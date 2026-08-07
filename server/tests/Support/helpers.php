<?php

declare(strict_types=1);

if (!function_exists('aiInvokeProtected')) {
    function aiInvokeProtected(object $object, string $method, mixed ...$arguments): mixed
    {
        $reflection = new ReflectionMethod($object, $method);
        $reflection->setAccessible(true);

        return $reflection->invokeArgs($object, $arguments);
    }
}

if (!function_exists('aiJsonPayload')) {
    function aiJsonPayload(mixed $response): array
    {
        if (is_object($response) && method_exists($response, 'getData')) {
            return $response->getData(true);
        }

        return is_object($response) && property_exists($response, 'data') ? $response->data : [];
    }
}
