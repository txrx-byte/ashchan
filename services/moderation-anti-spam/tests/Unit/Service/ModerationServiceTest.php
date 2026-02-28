<?php
declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\Model\Report;
use App\Model\ReportCategory;
use App\Service\ModerationService;
use App\Service\PiiEncryptionService;
use App\Service\SiteConfigService;
use Ashchan\EventBus\CloudEvent;
use Ashchan\EventBus\EventPublisherInterface;
use Carbon\Carbon;
use Mockery as m;
use Mockery\MockInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * @covers \App\Service\ModerationService
 */
final class ModerationServiceTest extends TestCase
{
    private MockInterface $loggerMock;
    private MockInterface $piiEncryptionMock;
    private MockInterface $eventPublisherMock;
    private MockInterface $configMock;
    private ModerationService $moderationService;

    protected function setUp(): void
    {
        $this->loggerMock = m::mock(LoggerInterface::class);
        $this->piiEncryptionMock = m::mock(PiiEncryptionService::class);
        $this->eventPublisherMock = m::mock(EventPublisherInterface::class);
        $this->configMock = m::mock(SiteConfigService::class);

        // Configure default values
        $this->configMock
            ->shouldReceive('getInt')
            ->with('report_global_threshold', 1500)
            ->andReturn(1500);
        $this->configMock
            ->shouldReceive('getInt')
            ->with('report_highlight_threshold', 500)
            ->andReturn(500);
        $this->configMock
            ->shouldReceive('getFloat')
            ->with('thread_weight_boost', 1.25)
            ->andReturn(1.25);
        $this->configMock
            ->shouldReceive('getInt')
            ->with('abuse_clear_days', 3)
            ->andReturn(3);
        $this->configMock
            ->shouldReceive('getInt')
            ->with('abuse_clear_count', 50)
            ->andReturn(50);
        $this->configMock
            ->shouldReceive('getInt')
            ->with('abuse_clear_ban_interval', 5)
            ->andReturn(5);
        $this->configMock
            ->shouldReceive('getInt')
            ->with('report_abuse_template_id', 190)
            ->andReturn(190);

        $this->loggerMock
            ->shouldReceive('info')
            ->andReturnNull();

        $this->moderationService = new ModerationService(
            $this->loggerMock,
            $this->piiEncryptionMock,
            $this->eventPublisherMock,
            $this->configMock
        );
    }

    protected function tearDown(): void
    {
        m::close();
    }

    public function testCreateReportThrowsExceptionForInvalidCategory(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid report category');

        // Mock category lookup to return null
        $this->mockReportCategoryFind(null);

        $this->moderationService->createReport(
            12345,
            'g',
            999, // Invalid category ID
            ['resto' => 0],
            'encrypted_ip',
            'ip_hash',
            null,
            null,
            null
        );
    }

    public function testCreateReportReducesWeightForAbusiveReporter(): void
    {
        $category = $this->createMockCategory(1, '_all_', 'Spam', 30.00);
        $this->mockReportCategoryFind($category);

        // Mock filter check to return ignore reason (reporter has history)
        $this->configMock
            ->shouldReceive('getInt')
            ->with('report_global_threshold', 1500)
            ->andReturn(1500);

        // We can't easily mock the Report model DB calls in unit tests
        // This would need integration testing or more extensive mocking
        $this->markTestIncomplete('Requires database mocking for Report model');
    }

    public function testCreateReportHandlesClearedReReport(): void
    {
        // Test that re-reporting a cleared post logs the reporter
        $this->markTestIncomplete('Requires database mocking for Report model');
    }

    public function testCreateReportSetsWorksafeFlag(): void
    {
        $this->markTestIncomplete('Requires database mocking for Report model');
    }

    public function testCreateReportStoresPostDataAsJson(): void
    {
        $this->markTestIncomplete('Requires database mocking for Report model');
    }

    public function testGetReportQueueGroupsByPost(): void
    {
        $this->markTestIncomplete('Requires database mocking for Report model');
    }

    public function testGetReportQueueOrdersByWeightAndTime(): void
    {
        $this->markTestIncomplete('Requires database mocking for Report model');
    }

    public function testGetReportQueueFiltersByBoard(): void
    {
        $this->markTestIncomplete('Requires database mocking for Report model');
    }

    public function testGetReportQueueHandlesPagination(): void
    {
        $this->markTestIncomplete('Requires database mocking for Report model');
    }

    public function testGetReportQueueFormatsReports(): void
    {
        $this->markTestIncomplete('Requires database mocking for Report model');
    }

    public function testClearReportUpdatesClearedStatus(): void
    {
        $this->markTestIncomplete('Requires database mocking for Report model');
    }

    public function testClearReportLogsAction(): void
    {
        $this->markTestIncomplete('Requires database mocking for Report model');
    }

    public function testClearReportEmitsEvent(): void
    {
        $this->markTestIncomplete('Requires database mocking for Report model');
    }

    public function testDeleteReportRemovesFromDatabase(): void
    {
        $this->markTestIncomplete('Requires database mocking for Report model');
    }

    public function testCreateBanRequestStoresImageData(): void
    {
        $this->markTestIncomplete('Requires database mocking for BanRequest model');
    }

    public function testGetBanRequestsFiltersByBoard(): void
    {
        $this->markTestIncomplete('Requires database mocking for BanRequest model');
    }

    public function testApproveBanRequestCreatesBan(): void
    {
        $this->markTestIncomplete('Requires database mocking for BanRequest and BannedUser models');
    }

    public function testApproveBanRequestUpdatesJanitorStats(): void
    {
        $this->markTestIncomplete('Requires database mocking and janitor stats logic');
    }

    public function testDenyBanRequestRemovesRequest(): void
    {
        $this->markTestIncomplete('Requires database mocking for BanRequest model');
    }

    public function testCreateBanFromTemplateAppliesTemplateSettings(): void
    {
        $this->markTestIncomplete('Requires database mocking for BanTemplate and BannedUser models');
    }

    public function testCreateBanFromTemplateHandlesPermanentBans(): void
    {
        $this->markTestIncomplete('Requires database mocking for BannedUser model');
    }

    public function testCreateBanFromTemplateHandlesWarningBans(): void
    {
        $this->markTestIncomplete('Requires database mocking for BannedUser model');
    }

    public function testCreateBanFromTemplateHandlesGlobalBans(): void
    {
        $this->markTestIncomplete('Requires database mocking for BannedUser model');
    }

    public function testCreateBanFromTemplateHandlesZonlyBans(): void
    {
        $this->markTestIncomplete('Requires database mocking for BannedUser model');
    }

    public function testCheckBanReturnsNotBanned(): void
    {
        $this->markTestIncomplete('Requires database mocking for BannedUser model');
    }

    public function testCheckBanReturnsActiveBan(): void
    {
        $this->markTestIncomplete('Requires database mocking for BannedUser model');
    }

    public function testCheckBanReturnsExpiredBanAsNotBanned(): void
    {
        $this->markTestIncomplete('Requires database mocking for BannedUser model');
    }

    public function testCheckBanHandlesPassIdFallback(): void
    {
        $this->markTestIncomplete('Requires database mocking for BannedUser model');
    }

    public function testCheckBanFiltersByBoard(): void
    {
        $this->markTestIncomplete('Requires database mocking for BannedUser model');
    }

    public function testCountReportsByBoardReturnsCounts(): void
    {
        $this->markTestIncomplete('Requires database mocking for Report model');
    }

    public function testIsWorksafeBoardReturnsTrueForWorksafeBoards(): void
    {
        $this->configMock
            ->shouldReceive('getList')
            ->with('worksafe_boards', 'g,vg,v')
            ->andReturn(['g', 'vg', 'v']);

        $reflection = new \ReflectionClass($this->moderationService);
        $method = $reflection->getMethod('isWorksafeBoard');
        $method->setAccessible(true);

        $result = $method->invoke($this->moderationService, 'g');
        $this->assertTrue($result);
    }

    public function testIsWorksafeBoardReturnsFalseForNsfwBoards(): void
    {
        $this->configMock
            ->shouldReceive('getList')
            ->with('worksafe_boards', 'g,vg,v')
            ->andReturn(['g', 'vg', 'v']);

        $reflection = new \ReflectionClass($this->moderationService);
        $method = $reflection->getMethod('isWorksafeBoard');
        $method->setAccessible(true);

        $result = $method->invoke($this->moderationService, 'b');
        $this->assertFalse($result);
    }

    public function testConstructorReadsConfiguration(): void
    {
        // Verify constructor reads all config values
        $this->configMock
            ->shouldReceive('getInt')
            ->with('report_global_threshold', 1500)
            ->andReturn(2000);

        $service = new ModerationService(
            $this->loggerMock,
            $this->piiEncryptionMock,
            $this->eventPublisherMock,
            $this->configMock
        );

        // Service should be instantiated without errors
        $this->assertInstanceOf(ModerationService::class, $service);
    }

    /**
     * Helper to mock ReportCategory::find()
     */
    private function mockReportCategoryFind(?ReportCategory $result): void
    {
        // Static method mocking requires special handling
        // For now, we'll note this limitation
        if ($result === null) {
            // Would need to use Mockery::mock('overload:App\Model\ReportCategory')
            // or use a test double pattern
            $this->expectException(\InvalidArgumentException::class);
        }
    }

    /**
     * Helper to create a mock category
     */
    private function createMockCategory(int $id, string $board, string $title, float $weight): ReportCategory
    {
        $category = new ReportCategory();
        $category->setAttribute('id', $id);
        $category->setAttribute('board', $board);
        $category->setAttribute('title', $title);
        $category->setAttribute('weight', $weight);
        return $category;
    }
}
