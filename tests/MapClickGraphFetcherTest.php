<?php

declare(strict_types=1);

namespace Tests;

use App\Service\MapClickGraphFetcher;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for MapClickGraphFetcher service.
 */
class MapClickGraphFetcherTest extends TestCase
{
    /**
     * Test building meteogram URL with valid coordinates.
     */
    public function testBuildMeteogramUrlWithValidCoordinates(): void
    {
        $fetcher = new MapClickGraphFetcher();
        
        $url = $fetcher->buildMeteogramUrl(40.1616, -85.7194);
        
        $this->assertStringContainsString('forecast.weather.gov/meteograms/Plotter.php', $url);
        $this->assertStringContainsString('lat=40.1616', $url);
        $this->assertStringContainsString('lon=-85.7194', $url);
        $this->assertStringContainsString('wfo=ALL', $url);
    }

    /**
     * Test extracting coordinates from a MapClick URL.
     */
    public function testExtractCoordsFromValidUrl(): void
    {
        $fetcher = new MapClickGraphFetcher();
        
        $url = 'https://forecast.weather.gov/MapClick.php?lat=40.1616&lon=-85.7194&lg=english&FcstType=graphical&menu=1';
        $coords = $fetcher->extractCoordsFromUrl($url);
        
        $this->assertNotNull($coords);
        $this->assertEqualsWithDelta(40.1616, $coords['lat'], 0.0001);
        $this->assertEqualsWithDelta(-85.7194, $coords['lon'], 0.0001);
    }

    /**
     * Test extracting coordinates from URL with negative values.
     */
    public function testExtractCoordsFromUrlWithNegativeValues(): void
    {
        $fetcher = new MapClickGraphFetcher();
        
        $url = 'https://forecast.weather.gov/MapClick.php?lat=-33.8688&lon=151.2093';
        $coords = $fetcher->extractCoordsFromUrl($url);
        
        $this->assertNotNull($coords);
        $this->assertEqualsWithDelta(-33.8688, $coords['lat'], 0.0001);
        $this->assertEqualsWithDelta(151.2093, $coords['lon'], 0.0001);
    }

    /**
     * Test extracting coordinates from invalid URL returns null.
     */
    public function testExtractCoordsFromInvalidUrlReturnsNull(): void
    {
        $fetcher = new MapClickGraphFetcher();
        
        $url = 'https://example.com/weather?location=chicago';
        $coords = $fetcher->extractCoordsFromUrl($url);
        
        $this->assertNull($coords);
    }

    /**
     * Test extracting coordinates from URL missing lat returns null.
     */
    public function testExtractCoordsFromUrlMissingLatReturnsNull(): void
    {
        $fetcher = new MapClickGraphFetcher();
        
        $url = 'https://forecast.weather.gov/MapClick.php?lon=-85.7194';
        $coords = $fetcher->extractCoordsFromUrl($url);
        
        $this->assertNull($coords);
    }

    /**
     * Test extracting coordinates from URL missing lon returns null.
     */
    public function testExtractCoordsFromUrlMissingLonReturnsNull(): void
    {
        $fetcher = new MapClickGraphFetcher();
        
        $url = 'https://forecast.weather.gov/MapClick.php?lat=40.1616';
        $coords = $fetcher->extractCoordsFromUrl($url);
        
        $this->assertNull($coords);
    }

    /**
     * Test fetching meteogram image successfully.
     */
    public function testFetchMeteogramImageSuccess(): void
    {
        // Create a mock PNG image
        $pngData = $this->createFakePngData();
        
        $mock = new MockHandler([
            new Response(200, ['Content-Type' => 'image/png'], $pngData),
        ]);
        
        $handlerStack = HandlerStack::create($mock);
        $client = new Client(['handler' => $handlerStack]);
        
        $fetcher = new MapClickGraphFetcher($client);
        $result = $fetcher->fetchMeteogramImage(40.1616, -85.7194);
        
        $this->assertNotNull($result);
        $this->assertEquals($pngData, $result['data']);
        $this->assertEquals('image/png', $result['content_type']);
    }

    /**
     * Test fetching meteogram image returns null on HTTP error.
     */
    public function testFetchMeteogramImageReturnsNullOnHttpError(): void
    {
        $mock = new MockHandler([
            new Response(500, [], 'Internal Server Error'),
        ]);
        
        $handlerStack = HandlerStack::create($mock);
        $client = new Client(['handler' => $handlerStack]);
        
        $fetcher = new MapClickGraphFetcher($client);
        $result = $fetcher->fetchMeteogramImage(40.1616, -85.7194);
        
        $this->assertNull($result);
    }

    /**
     * Test fetching meteogram image returns null for non-image content type.
     */
    public function testFetchMeteogramImageReturnsNullForNonImageContentType(): void
    {
        $mock = new MockHandler([
            new Response(200, ['Content-Type' => 'text/html'], '<html>Error page</html>'),
        ]);
        
        $handlerStack = HandlerStack::create($mock);
        $client = new Client(['handler' => $handlerStack]);
        
        $fetcher = new MapClickGraphFetcher($client);
        $result = $fetcher->fetchMeteogramImage(40.1616, -85.7194);
        
        $this->assertNull($result);
    }

    /**
     * Test fetching meteogram image returns null for too small content.
     */
    public function testFetchMeteogramImageReturnsNullForSmallContent(): void
    {
        $mock = new MockHandler([
            new Response(200, ['Content-Type' => 'image/png'], 'small'),
        ]);
        
        $handlerStack = HandlerStack::create($mock);
        $client = new Client(['handler' => $handlerStack]);
        
        $fetcher = new MapClickGraphFetcher($client);
        $result = $fetcher->fetchMeteogramImage(40.1616, -85.7194);
        
        $this->assertNull($result);
    }

    /**
     * Test fetching from MapClick URL.
     */
    public function testFetchFromMapClickUrl(): void
    {
        $pngData = $this->createFakePngData();
        
        $mock = new MockHandler([
            new Response(200, ['Content-Type' => 'image/png'], $pngData),
        ]);
        
        $handlerStack = HandlerStack::create($mock);
        $client = new Client(['handler' => $handlerStack]);
        
        $fetcher = new MapClickGraphFetcher($client);
        $url = 'https://forecast.weather.gov/MapClick.php?lat=40.1616&lon=-85.7194&FcstType=graphical';
        $result = $fetcher->fetchFromMapClickUrl($url);
        
        $this->assertNotNull($result);
        $this->assertEquals($pngData, $result['data']);
        $this->assertEquals('image/png', $result['content_type']);
    }

    /**
     * Test fetching from MapClick URL returns null for invalid URL.
     */
    public function testFetchFromMapClickUrlReturnsNullForInvalidUrl(): void
    {
        $fetcher = new MapClickGraphFetcher();
        $result = $fetcher->fetchFromMapClickUrl('https://example.com/weather');
        
        $this->assertNull($result);
    }

    /**
     * Test content type normalization for JPEG.
     */
    public function testContentTypeNormalizationJpeg(): void
    {
        $jpegData = $this->createFakeJpegData();
        
        $mock = new MockHandler([
            new Response(200, ['Content-Type' => 'image/jpeg; charset=binary'], $jpegData),
        ]);
        
        $handlerStack = HandlerStack::create($mock);
        $client = new Client(['handler' => $handlerStack]);
        
        $fetcher = new MapClickGraphFetcher($client);
        $result = $fetcher->fetchMeteogramImage(40.1616, -85.7194);
        
        $this->assertNotNull($result);
        $this->assertEquals('image/jpeg', $result['content_type']);
    }

    /**
     * Create fake PNG data for testing.
     */
    private function createFakePngData(): string
    {
        // PNG magic bytes followed by some data
        return "\x89PNG\r\n\x1a\n" . str_repeat('x', 200);
    }

    /**
     * Create fake JPEG data for testing.
     */
    private function createFakeJpegData(): string
    {
        // JPEG magic bytes followed by some data
        return "\xFF\xD8\xFF\xE0" . str_repeat('x', 200);
    }
}
