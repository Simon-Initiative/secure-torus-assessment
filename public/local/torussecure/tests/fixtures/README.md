# Config Key fixture

`known.seb` deliberately stores its three keys out of order. Its expected Config Key is
`f08714a7ae16cc9a16cb7f7ee286c42fad160d3ddb8d0f0a89105b40a85e8f8e`.
This was independently calculated as SHA-256 of these exact UTF-8 bytes, with no trailing newline:

```json
{"allowQuit":true,"sendBrowserExamKey":true,"startURL":"https://moodle.test:8443/mod/lti/view.php?id=42"}
```

This follows the [SEB Config Key specification](https://safeexambrowser.org/developer/seb-config-key.html).
The adapter test also checks Moodle's existing `quizaccess_seb` macOS 2.1.4 client fixture against
its pinned known key. That fixture stays at its original path with its original attribution.
