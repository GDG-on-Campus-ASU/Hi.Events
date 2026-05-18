<?php

namespace Tests\Unit\Services\Application\Handlers\Attendee;

use HiEvents\DomainObjects\AttendeeDomainObject;
use HiEvents\DomainObjects\ProductDomainObject;
use HiEvents\DomainObjects\ProductPriceDomainObject;
use HiEvents\Repository\Interfaces\AttendeeRepositoryInterface;
use HiEvents\Repository\Interfaces\ProductRepositoryInterface;
use HiEvents\Services\Application\Handlers\Attendee\CreateAttendeeHandler;
use HiEvents\Services\Application\Handlers\Attendee\DTO\ImportAttendeesDTO;
use HiEvents\Services\Application\Handlers\Attendee\ImportAttendeesHandler;
use Illuminate\Http\UploadedFile;
use Maatwebsite\Excel\Facades\Excel;
use Mockery;
use Mockery\MockInterface;
use Tests\TestCase;

class ImportAttendeesHandlerTest extends TestCase
{
    private CreateAttendeeHandler|MockInterface $createAttendeeHandler;

    private ProductRepositoryInterface|MockInterface $productRepository;

    private AttendeeRepositoryInterface|MockInterface $attendeeRepository;

    private ImportAttendeesHandler $handler;

    protected function setUp(): void
    {
        parent::setUp();

        $this->createAttendeeHandler = Mockery::mock(CreateAttendeeHandler::class);
        $this->productRepository = Mockery::mock(ProductRepositoryInterface::class);
        $this->attendeeRepository = Mockery::mock(AttendeeRepositoryInterface::class);

        $this->handler = new ImportAttendeesHandler(
            $this->createAttendeeHandler,
            $this->productRepository,
            $this->attendeeRepository,
        );
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_skips_duplicate_attendee_by_email(): void
    {
        $eventId = 1;
        $productId = 10;
        $productPriceId = 100;
        $email = 'duplicate@example.com';

        // Create a mock CSV with two rows - one duplicate
        $csvRows = collect([
            collect([
                'firstname' => 'John',
                'lastname' => 'Doe',
                'email' => $email,
                'language' => 'English',
                'ticket' => 'VIP Ticket',
                'paid' => '50.00',
            ]),
        ]);

        // Mock the Excel import
        Excel::shouldReceive('import')
            ->once()
            ->andReturnUsing(function ($import, $file) use ($csvRows) {
                $reflection = new \ReflectionClass($import);
                $property = $reflection->getProperty('rows');
                $property->setAccessible(true);
                $property->setValue($import, $csvRows);
            });

        // Mock product repository to return a product
        $productPrice = Mockery::mock(ProductPriceDomainObject::class);
        $productPrice->shouldReceive('getId')->andReturn($productPriceId);

        $product = Mockery::mock(ProductDomainObject::class);
        $product->shouldReceive('getId')->andReturn($productId);
        $product->shouldReceive('getTitle')->andReturn('VIP Ticket');
        $product->shouldReceive('getProductPrices')->andReturn(collect([$productPrice]));

        $this->productRepository
            ->shouldReceive('loadRelation')
            ->once()
            ->andReturnSelf();

        $this->productRepository
            ->shouldReceive('findWhere')
            ->once()
            ->andReturn(collect([$product]));

        // Mock attendee repository to return an existing attendee (duplicate)
        $existingAttendee = Mockery::mock(AttendeeDomainObject::class);
        $this->attendeeRepository
            ->shouldReceive('findFirstWhere')
            ->with([
                'email' => strtolower($email),
                'event_id' => $eventId,
                'product_id' => $productId,
            ])
            ->once()
            ->andReturn($existingAttendee);

        // Create the DTO
        $dto = ImportAttendeesDTO::fromArray([
            'event_id' => $eventId,
            'send_confirmation_email' => false,
            'file' => UploadedFile::fake()->create('test.csv', 100),
        ]);

        // The createAttendeeHandler should NOT be called since the attendee is a duplicate
        $this->createAttendeeHandler->shouldNotReceive('handle');

        $result = $this->handler->handle($dto);

        // Assert that the result shows 0 successful, 0 failed, 1 skipped
        $this->assertEquals(0, $result['successful']);
        $this->assertEquals(0, $result['failed']);
        $this->assertEquals(1, $result['skipped']);
        $this->assertCount(1, $result['errors']);
        $this->assertStringContainsString('already exists', $result['errors'][0]['message']);
    }

    public function test_creates_attendee_when_no_existing_duplicate(): void
    {
        $eventId = 1;
        $productId = 10;
        $productPriceId = 100;
        $email = 'new@example.com';

        // Create a mock CSV with one row
        $csvRows = collect([
            collect([
                'firstname' => 'Jane',
                'lastname' => 'Doe',
                'email' => $email,
                'language' => 'English',
                'ticket' => 'VIP Ticket',
                'paid' => '50.00',
            ]),
        ]);

        // Mock the Excel import
        Excel::shouldReceive('import')
            ->once()
            ->andReturnUsing(function ($import, $file) use ($csvRows) {
                $reflection = new \ReflectionClass($import);
                $property = $reflection->getProperty('rows');
                $property->setAccessible(true);
                $property->setValue($import, $csvRows);
            });

        // Mock product repository to return a product
        $productPrice = Mockery::mock(ProductPriceDomainObject::class);
        $productPrice->shouldReceive('getId')->andReturn($productPriceId);

        $product = Mockery::mock(ProductDomainObject::class);
        $product->shouldReceive('getId')->andReturn($productId);
        $product->shouldReceive('getTitle')->andReturn('VIP Ticket');
        $product->shouldReceive('getProductPrices')->andReturn(collect([$productPrice]));

        $this->productRepository
            ->shouldReceive('loadRelation')
            ->once()
            ->andReturnSelf();

        $this->productRepository
            ->shouldReceive('findWhere')
            ->once()
            ->andReturn(collect([$product]));

        // Mock attendee repository to return null (no existing attendee)
        $this->attendeeRepository
            ->shouldReceive('findFirstWhere')
            ->with([
                'email' => strtolower($email),
                'event_id' => $eventId,
                'product_id' => $productId,
            ])
            ->once()
            ->andReturn(null);

        // Create the DTO
        $dto = ImportAttendeesDTO::fromArray([
            'event_id' => $eventId,
            'send_confirmation_email' => false,
            'file' => UploadedFile::fake()->create('test.csv', 100),
        ]);

        // The createAttendeeHandler should be called since no duplicate exists
        $this->createAttendeeHandler
            ->shouldReceive('handle')
            ->once()
            ->andReturn(Mockery::mock(AttendeeDomainObject::class));

        $result = $this->handler->handle($dto);

        // Assert that the result shows 1 successful, 0 failed, 0 skipped
        $this->assertEquals(1, $result['successful']);
        $this->assertEquals(0, $result['failed']);
        $this->assertEquals(0, $result['skipped']);
        $this->assertCount(0, $result['errors']);
    }
}
