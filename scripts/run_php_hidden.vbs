Option Explicit

Dim shell, phpExe, scriptPath, command
Set shell = CreateObject("WScript.Shell")

phpExe = "R:\xxxamp\php\php.exe"

If WScript.Arguments.Count < 1 Then
    WScript.Quit 1
End If

scriptPath = WScript.Arguments(0)
command = """" & phpExe & """ """ & scriptPath & """"

' 0 = hidden window, True = wait for completion
shell.Run command, 0, True
