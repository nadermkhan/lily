Write-Host "Starting QA Tests..." -ForegroundColor Cyan
php tests/qa_tester.php
$exitCode = $LASTEXITCODE

if ($exitCode -eq 0) {
    Write-Host "All QA Tests Passed!" -ForegroundColor Green
} else {
    Write-Host "QA Tests Failed!" -ForegroundColor Red
}

exit $exitCode
