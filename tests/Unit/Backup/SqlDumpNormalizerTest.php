<?php

declare(strict_types=1);

namespace DataVeil\Tests\Unit\Backup;

use DataVeil\Backup\SqlDumpNormalizer;
use PHPUnit\Framework\TestCase;

class SqlDumpNormalizerTest extends TestCase
{
    public function testRemovesSavedCharacterSetClientStatements(): void
    {
        $sql = <<<'SQL'
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `b_user` (`ID` int NOT NULL);
/*!40101 SET character_set_client = @saved_cs_client */;
INSERT INTO `b_user` VALUES (1);
SQL;

        $normalized = (new SqlDumpNormalizer())->normalize($sql);

        $this->assertStringNotContainsString('@saved_cs_client', $normalized);
        $this->assertStringContainsString('SET character_set_client = utf8mb4', $normalized);
        $this->assertStringContainsString('CREATE TABLE `b_user`', $normalized);
        $this->assertStringContainsString('INSERT INTO `b_user`', $normalized);
    }

    public function testRemovesOldSessionVariableRestoreStatements(): void
    {
        $sql = <<<'SQL'
/*!40103 SET @OLD_TIME_ZONE=@@TIME_ZONE */;
/*!40103 SET TIME_ZONE='+00:00' */;
/*!40014 SET @OLD_UNIQUE_CHECKS=@@UNIQUE_CHECKS, UNIQUE_CHECKS=0 */;
/*!40014 SET @OLD_FOREIGN_KEY_CHECKS=@@FOREIGN_KEY_CHECKS, FOREIGN_KEY_CHECKS=0 */;
/*!40101 SET @OLD_SQL_MODE=@@SQL_MODE, SQL_MODE='NO_AUTO_VALUE_ON_ZERO' */;
/*!40111 SET @OLD_SQL_NOTES=@@SQL_NOTES, SQL_NOTES=0 */;
CREATE TABLE `b_crm_deal` (`ID` int NOT NULL);
/*!40103 SET TIME_ZONE=@OLD_TIME_ZONE */;
/*!40101 SET SQL_MODE=@OLD_SQL_MODE */;
/*!40014 SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS */;
/*!40014 SET UNIQUE_CHECKS=@OLD_UNIQUE_CHECKS */;
/*!40111 SET SQL_NOTES=@OLD_SQL_NOTES */;
SQL;

        $normalized = (new SqlDumpNormalizer())->normalize($sql);

        $this->assertStringNotContainsString('@OLD_TIME_ZONE', $normalized);
        $this->assertStringNotContainsString('@OLD_SQL_MODE', $normalized);
        $this->assertStringNotContainsString('@OLD_FOREIGN_KEY_CHECKS', $normalized);
        $this->assertStringNotContainsString('@OLD_UNIQUE_CHECKS', $normalized);
        $this->assertStringNotContainsString('@OLD_SQL_NOTES', $normalized);
        $this->assertStringContainsString("SET TIME_ZONE='+00:00'", $normalized);
        $this->assertStringNotContainsString('SET UNIQUE_CHECKS=0', $normalized);
        $this->assertStringNotContainsString('SET FOREIGN_KEY_CHECKS=0', $normalized);
        $this->assertStringNotContainsString("SET SQL_MODE='NO_AUTO_VALUE_ON_ZERO'", $normalized);
        $this->assertStringNotContainsString('SET SQL_NOTES=0', $normalized);
        $this->assertStringContainsString('CREATE TABLE `b_crm_deal`', $normalized);
    }
}
