<?php

declare(strict_types=1);

namespace Goal\Legacy\Core\Persistence;

/**
 * Ownership labels used by the bounded storage inventory and cleanup tools.
 *
 * A label is only assigned when the storage root and the artifact convention
 * both identify the file.  Unrecognised files remain UNKNOWN_EXTERNAL.
 */
enum StorageArtifactType: string
{
    case NormalCareerSave = 'NORMAL_CAREER_SAVE';
    case SandboxSave = 'SANDBOX_SAVE';
    case TempTestSave = 'TEMP_TEST_SAVE';
    case TempSimulationSave = 'TEMP_SIMULATION_SAVE';
    case BrowserTestSave = 'BROWSER_TEST_SAVE';
    case RecoverySnapshot = 'RECOVERY_SNAPSHOT';
    case SandboxSnapshot = 'SANDBOX_SNAPSHOT';
    case DiagnosticArtifact = 'DIAGNOSTIC_ARTIFACT';
    case UnknownExternal = 'UNKNOWN_EXTERNAL';
}
