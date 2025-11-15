$taskName = 'UpdateHsDatasetMonthly'
$phpPath = 'C:\wamp64\bin\php\php8.3.6\php.exe'
$scriptPath = 'C:\Users\ASUS\Desktop\Coresuite-Business-1\tools\update_hs_dataset.php'
$workingDirectory = 'C:\Users\ASUS\Desktop\Coresuite-Business-1'

$schedule = New-Object -ComObject 'Schedule.Service'
$schedule.Connect()
$rootFolder = $schedule.GetFolder('\')

try {
    $rootFolder.DeleteTask($taskName, 0)
}
catch {
    # Il task potrebbe non esistere ancora, ignora l'errore
}

$taskDefinition = $schedule.NewTask(0)
$taskDefinition.RegistrationInfo.Description = 'Aggiorna automaticamente il dataset HS e mantiene la cache traduzioni.'
$taskDefinition.Principal.UserId = $env:UserName
$taskDefinition.Principal.LogonType = 3 # TASK_LOGON_INTERACTIVE_TOKEN
$taskDefinition.Principal.RunLevel = 0   # TASK_RUNLEVEL_LUA (Least privilege)

$today = Get-Date
$firstOfThisMonth = Get-Date -Year $today.Year -Month $today.Month -Day 1 -Hour 3 -Minute 0 -Second 0
if ($firstOfThisMonth -le $today) {
    $startBoundary = $firstOfThisMonth.AddMonths(1)
}
else {
    $startBoundary = $firstOfThisMonth
}

$trigger = $taskDefinition.Triggers.Create(4) # TASK_TRIGGER_MONTHLY (per giorno del mese)
$trigger.StartBoundary = $startBoundary.ToString('s')
$trigger.DaysOfMonth = 1
$trigger.MonthsOfYear = 4095 # tutti i mesi
$trigger.Enabled = $true

$action = $taskDefinition.Actions.Create(0) # TASK_ACTION_EXEC
$action.Path = $phpPath
$action.Arguments = '"' + $scriptPath + '" --keep-cache'
$action.WorkingDirectory = $workingDirectory

$rootFolder.RegisterTaskDefinition($taskName, $taskDefinition, 6, $null, $null, 3)
Write-Host "Attività pianificata '$taskName' registrata con esecuzione mensile (giorno 1 alle 03:00)."
