Set WinScriptHost = CreateObject("WScript.Shell")
WinScriptHost.Run Chr(34) & "C:\web server\htdocs\vmk\test\update-task.bat" & Chr(34), 0
Set WinScriptHost = Nothing