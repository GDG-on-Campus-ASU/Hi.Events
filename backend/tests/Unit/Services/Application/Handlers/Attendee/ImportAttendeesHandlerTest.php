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
use Illuminate\Support\Facades\Log;
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

        // Expect duplicate to be logged
        Log::shouldReceive('info')
            ->once()
            ->with('Duplicate attendee skipped during CSV import', Mockery::type('array'));

        $result = $this->handler->handle($dto);

        // Assert that the result shows 0 successful, 0 failed, 1 skipped
        // Duplicates are now silently logged instead of added to errors
        $this->assertEquals(0, $result['successful']);
        $this->assertEquals(0, $result['failed']);
        $this->assertEquals(1, $result['skipped']);
        $this->assertCount(0, $result['errors']);
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

    public function test_skips_duplicate_attendee_by_email_case_insensitive(): void
    {
        $eventId = 1;
        $productId = 10;
        $productPriceId = 100;
        // CSV email has mixed case
        $emailInCsv = 'DUPLICATE@EXAMPLE.COM';
        // Existing email in database is lowercase
        $emailInDb = 'duplicate@example.com';

        // Create a mock CSV with email in uppercase
        $csvRows = collect([
            collect([
                'firstname' => 'John',
                'lastname' => 'Doe',
                'email' => $emailInCsv,
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

        // Mock attendee repository to return an existing attendee (duplicate detection with lowercase email)
        $existingAttendee = Mockery::mock(AttendeeDomainObject::class);
        $this->attendeeRepository
            ->shouldReceive('findFirstWhere')
            ->with([
                'email' => $emailInDb, // Email should be normalized to lowercase
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

        // Expect duplicate to be logged
        Log::shouldReceive('info')
            ->once()
            ->with('Duplicate attendee skipped during CSV import', Mockery::type('array'));

        $result = $this->handler->handle($dto);

        // Assert that the result shows 0 successful, 0 failed, 1 skipped
        // Duplicates are now silently logged instead of added to errors
        $this->assertEquals(0, $result['successful']);
        $this->assertEquals(0, $result['failed']);
        $this->assertEquals(1, $result['skipped']);
        $this->assertCount(0, $result['errors']);
    }

    public function test_sanitizes_name_fields_removing_special_characters(): void
    {
        $eventId = 1;
        $productId = 10;
        $productPriceId = 100;
        $email = 'test@example.com';

        // Create a mock CSV with special characters in names
        $csvRows = collect([
            collect([
                'firstname' => 'John-Robert.James_Third',
                'lastname' => "O'Brien<script>alert('xss')</script>",
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
            ->once()
            ->andReturn(null);

        // Create the DTO
        $dto = ImportAttendeesDTO::fromArray([
            'event_id' => $eventId,
            'send_confirmation_email' => false,
            'file' => UploadedFile::fake()->create('test.csv', 100),
        ]);

        // Capture the createAttendeeHandler call to verify sanitized values
        $capturedDTO = null;
        $this->createAttendeeHandler
            ->shouldReceive('handle')
            ->once()
            ->andReturnUsing(function ($dto) use (&$capturedDTO) {
                $capturedDTO = $dto;

                return Mockery::mock(AttendeeDomainObject::class);
            });

        $result = $this->handler->handle($dto);

        // Assert sanitization: dashes, dots, underscores replaced with spaces, special chars removed
        // "John-Robert.James_Third" becomes "John Robert James Third"
        $this->assertEquals('John Robert James Third', $capturedDTO->first_name);
        // "O'Brien<script>alert('xss')</script>" becomes "OBrienscriptalertxssscript"
        $this->assertEquals('OBrienscriptalertxssscript', $capturedDTO->last_name);
        $this->assertEquals(1, $result['successful']);
    }

    public function test_sanitizes_email_removing_special_characters(): void
    {
        $eventId = 1;
        $productId = 10;
        $productPriceId = 100;
        // Email with special characters that should be removed
        $maliciousEmail = 'test<script>@example.com';

        // Create a mock CSV with special characters in email
        $csvRows = collect([
            collect([
                'firstname' => 'John',
                'lastname' => 'Doe',
                'email' => $maliciousEmail,
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
            ->once()
            ->andReturn(null);

        // Create the DTO
        $dto = ImportAttendeesDTO::fromArray([
            'event_id' => $eventId,
            'send_confirmation_email' => false,
            'file' => UploadedFile::fake()->create('test.csv', 100),
        ]);

        // Capture the createAttendeeHandler call to verify sanitized values
        $capturedDTO = null;
        $this->createAttendeeHandler
            ->shouldReceive('handle')
            ->once()
            ->andReturnUsing(function ($dto) use (&$capturedDTO) {
                $capturedDTO = $dto;

                return Mockery::mock(AttendeeDomainObject::class);
            });

        $result = $this->handler->handle($dto);

        // Assert sanitization: special characters removed and email lowercased
        // "test<script>@example.com" becomes "testscript@example.com"
        $this->assertEquals('testscript@example.com', $capturedDTO->email);
        $this->assertEquals(1, $result['successful']);
    }

    public function test_allows_arabic_characters_in_name_fields(): void
    {
        $eventId = 1;
        $productId = 10;
        $productPriceId = 100;
        $email = 'arabic@example.com';

        // Arabic names
        $arabicFirstName = 'محمد';
        $arabicLastName = 'أحمد';

        // Create a mock CSV with Arabic names
        $csvRows = collect([
            collect([
                'firstname' => $arabicFirstName,
                'lastname' => $arabicLastName,
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
            ->once()
            ->andReturn(null);

        // Create the DTO
        $dto = ImportAttendeesDTO::fromArray([
            'event_id' => $eventId,
            'send_confirmation_email' => false,
            'file' => UploadedFile::fake()->create('test.csv', 100),
        ]);

        // Capture the createAttendeeHandler call to verify Arabic names are preserved
        $capturedDTO = null;
        $this->createAttendeeHandler
            ->shouldReceive('handle')
            ->once()
            ->andReturnUsing(function ($dto) use (&$capturedDTO) {
                $capturedDTO = $dto;

                return Mockery::mock(AttendeeDomainObject::class);
            });

        $result = $this->handler->handle($dto);

        // Assert that Arabic names are preserved
        $this->assertEquals($arabicFirstName, $capturedDTO->first_name);
        $this->assertEquals($arabicLastName, $capturedDTO->last_name);
        $this->assertEquals(1, $result['successful']);
    }

    public function test_allows_unicode_characters_in_ticket_name(): void
    {
        $eventId = 1;
        $productId = 10;
        $productPriceId = 100;
        $email = 'test@example.com';

        // Arabic ticket name
        $arabicTicketName = 'تذكرة VIP';

        // Create a mock CSV with Arabic ticket name
        $csvRows = collect([
            collect([
                'firstname' => 'John',
                'lastname' => 'Doe',
                'email' => $email,
                'language' => 'English',
                'ticket' => $arabicTicketName,
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

        // Mock product repository to return a product with Arabic name
        $productPrice = Mockery::mock(ProductPriceDomainObject::class);
        $productPrice->shouldReceive('getId')->andReturn($productPriceId);

        $product = Mockery::mock(ProductDomainObject::class);
        $product->shouldReceive('getId')->andReturn($productId);
        $product->shouldReceive('getTitle')->andReturn($arabicTicketName);
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
            ->once()
            ->andReturn(null);

        // Create the DTO
        $dto = ImportAttendeesDTO::fromArray([
            'event_id' => $eventId,
            'send_confirmation_email' => false,
            'file' => UploadedFile::fake()->create('test.csv', 100),
        ]);

        // The createAttendeeHandler should be called successfully with Arabic ticket
        $this->createAttendeeHandler
            ->shouldReceive('handle')
            ->once()
            ->andReturn(Mockery::mock(AttendeeDomainObject::class));

        $result = $this->handler->handle($dto);

        // Assert that the attendee was created successfully
        $this->assertEquals(1, $result['successful']);
        $this->assertEquals(0, $result['failed']);
    }

    public function test_allows_various_unicode_characters_in_names(): void
    {
        $eventId = 1;
        $productId = 10;
        $productPriceId = 100;
        $email = 'unicode@example.com';

        // Various Unicode names (Chinese, Japanese, Korean, Cyrillic, etc.)
        $chineseFirstName = '李明';
        $japaneseName = 'たかし';

        // Create a mock CSV with various Unicode names
        $csvRows = collect([
            collect([
                'firstname' => $chineseFirstName,
                'lastname' => $japaneseName,
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
            ->once()
            ->andReturn(null);

        // Create the DTO
        $dto = ImportAttendeesDTO::fromArray([
            'event_id' => $eventId,
            'send_confirmation_email' => false,
            'file' => UploadedFile::fake()->create('test.csv', 100),
        ]);

        // Capture the createAttendeeHandler call to verify Unicode names are preserved
        $capturedDTO = null;
        $this->createAttendeeHandler
            ->shouldReceive('handle')
            ->once()
            ->andReturnUsing(function ($dto) use (&$capturedDTO) {
                $capturedDTO = $dto;

                return Mockery::mock(AttendeeDomainObject::class);
            });

        $result = $this->handler->handle($dto);

        // Assert that Unicode names are preserved
        $this->assertEquals($chineseFirstName, $capturedDTO->first_name);
        $this->assertEquals($japaneseName, $capturedDTO->last_name);
        $this->assertEquals(1, $result['successful']);
    }
}
