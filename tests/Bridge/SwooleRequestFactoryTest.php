<?php

declare(strict_types=1);

namespace Byfareska\SwooleServer\Tests\Bridge;

use Byfareska\SwooleServer\Bridge\SwooleRequestFactory;
use PHPUnit\Framework\TestCase;

final class SwooleRequestFactoryTest extends TestCase
{
    private SwooleRequestFactory $factory;

    protected function setUp(): void
    {
        $this->factory = new SwooleRequestFactory();
    }

    public function testUppercasesServerKeys(): void
    {
        $req = new \Swoole\Http\Request();
        $req->server = [
            'request_method' => 'POST',
            'request_uri' => '/foo',
            'server_protocol' => 'HTTP/1.1',
        ];

        $request = $this->factory->createRequest($req);

        self::assertSame('POST', $request->server->get('REQUEST_METHOD'));
        self::assertSame('/foo', $request->server->get('REQUEST_URI'));
        self::assertSame('POST', $request->getMethod());
    }

    public function testHeadersGetHttpPrefixExceptContentTypeAndLength(): void
    {
        $req = new \Swoole\Http\Request();
        $req->server = ['request_method' => 'POST', 'request_uri' => '/'];
        $req->header = [
            'x-custom-header' => 'abc',
            'content-type' => 'application/json',
            'content-length' => '42',
        ];

        $request = $this->factory->createRequest($req);

        self::assertSame('abc', $request->server->get('HTTP_X_CUSTOM_HEADER'));
        self::assertSame('abc', $request->headers->get('X-Custom-Header'));
        self::assertSame('application/json', $request->server->get('CONTENT_TYPE'));
        self::assertSame('42', $request->server->get('CONTENT_LENGTH'));
        self::assertNull($request->server->get('HTTP_CONTENT_TYPE'));
    }

    public function testPassesQueryPostCookiesAndFilesThrough(): void
    {
        $req = new \Swoole\Http\Request();
        $req->server = ['request_method' => 'POST', 'request_uri' => '/'];
        $req->get = ['page' => '2'];
        $req->post = ['name' => 'x'];
        $req->cookie = ['sid' => 'abc'];
        $req->files = ['upload' => ['name' => 'a.txt']];

        $request = $this->factory->createRequest($req);

        self::assertSame('2', $request->query->get('page'));
        self::assertSame('x', $request->request->get('name'));
        self::assertSame('abc', $request->cookies->get('sid'));
        self::assertSame(['upload' => ['name' => 'a.txt']], $request->files->all());
    }

    public function testRawBodyIsExposedAsContent(): void
    {
        $req = new \Swoole\Http\Request();
        $req->server = ['request_method' => 'POST', 'request_uri' => '/'];
        $req->content = '{"a":1}';

        $request = $this->factory->createRequest($req);

        self::assertSame('{"a":1}', $request->getContent());
    }

    public function testNullSwooleArraysAreTolerated(): void
    {
        $req = new \Swoole\Http\Request();

        $request = $this->factory->createRequest($req);

        self::assertSame([], $request->query->all());
        self::assertSame([], $request->request->all());
        self::assertSame([], $request->cookies->all());
    }
}
