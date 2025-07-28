# GitHub Copilot Instructions for wphaven-connect

## Environment Context
- **Primary OS**: Windows (PowerShell/pwsh.exe) and macOS (bash/zsh)
- **Docker**: Used for WordPress development environment (works on both platforms)
- **Shell Differences**: 
  - Windows: PowerShell (pwsh.exe) - prefer WSL for Unix commands
  - macOS: bash/zsh - native Unix commands work directly

## Cross-Platform Command Guidelines

### File Operations

#### ✅ PREFERRED: Use Copilot tools (works on all platforms)
Use the built-in tools instead of shell commands:
- `grep_search`: Search content within files
- `file_search`: Find files by glob pattern
- `semantic_search`: Natural language searches in codebase
- `read_file`: Read file contents

#### ✅ Platform-Specific Commands (when tools aren't sufficient)

**On macOS/Linux:**
```bash
# These work natively
find . -name "*.php" -exec grep -l "pattern" {} \;
grep -r "pattern" --include="*.php" .
docker compose exec wordpress tail -n 10 /var/www/html/wp-content/debug.log | grep "Custom login"
```

**On Windows:**
```powershell
# Simple commands work in PowerShell
docker compose exec wordpress tail -n 10 /var/www/html/wp-content/debug.log

# For complex commands with pipes, use WSL
wsl bash -c "docker compose exec wordpress tail -n 10 /var/www/html/wp-content/debug.log | grep 'Custom login'"
```

### Docker Commands (Cross-Platform)

#### ✅ PREFERRED: Use Copilot tools for container operations
- `run_in_terminal`: Execute commands in containers
- `get_terminal_output`: Check running command output

#### ✅ CORRECT Docker commands (work on all platforms)
```bash
# Basic container operations
docker compose up -d
docker compose down
docker compose ps
docker compose restart wordpress

# Simple exec commands (work everywhere)
docker compose exec wordpress bash
docker compose exec wordpress tail -n 10 /var/www/html/wp-content/debug.log
```

#### ⚠️ Platform-Specific Docker Commands

**macOS/Linux:**
```bash
# Pipes work natively
docker compose exec wordpress tail -n 20 /var/www/html/wp-content/debug.log | grep "Custom login"
docker compose exec wordpress ls -la /var/www/html/ | grep htaccess
```

**Windows PowerShell:**
```powershell
# For commands with pipes, use WSL wrapper
wsl bash -c "docker compose exec wordpress tail -n 20 /var/www/html/wp-content/debug.log | grep 'Custom login'"
```
wsl bash -c "docker compose exec wordpress tail -n 20 /var/www/html/wp-content/debug.log | grep 'Custom login'"
```

### File Paths (Cross-Platform)

#### ✅ CORRECT: Use absolute paths in tools
**Windows:**
```
c:\Users\tyler\Code\embold\wphaven-connect\src\ErrorHandler.php
```

**macOS/Linux:**
```
/Users/username/Code/embold/wphaven-connect/src/ErrorHandler.php
```

#### ✅ PREFERRED: Use forward slashes in code
```php
// Works on all platforms
require_once __DIR__ . '/vendor/autoload.php';
```

### Terminal Command Priority (All Platforms):
1. **First choice**: Use Copilot tools (`grep_search`, `file_search`, `run_in_terminal`, etc.)
2. **Second choice**: Simple Docker commands without pipes
3. **Third choice**: Platform-native commands
   - macOS/Linux: Unix commands work directly  
   - Windows: Use WSL for Unix-style commands (`wsl bash -c "command"`)
4. **Last resort**: Complex platform-specific shell operations

### WordPress Development Specific

#### ✅ Debug log location in Docker container
```
/var/www/html/wp-content/debug.log
```

#### ✅ WordPress paths in container
- Document root: `/var/www/html/`
- Plugins: `/var/www/html/wp-content/plugins/`
- Themes: `/var/www/html/wp-content/themes/`

## Error Patterns to Avoid (Cross-Platform)

### Windows-Specific Issues
1. **Unix find commands in PowerShell**: Always fail
   - **❌ WRONG**: `find . -name "*.php"` (in PowerShell)
   - **✅ CORRECT**: Use `file_search` tool or `wsl find . -name "*.php"`

2. **Pipe operations in PowerShell with Docker**: 
   - **❌ WRONG**: `docker compose exec wordpress ls | grep pattern`
   - **✅ CORRECT**: `wsl bash -c "docker compose exec wordpress ls | grep pattern"`

3. **Path separators**: Windows uses backslashes in file system
   - **Solution**: Use absolute paths in tools; forward slashes work in code

### macOS/Linux-Specific Issues
1. **Case sensitivity**: File system is case-sensitive (unlike Windows)
   - **Solution**: Be precise with file and directory names
   
2. **Permissions**: May need `sudo` for some operations
   - **Solution**: Avoid `sudo` when possible; use Docker for isolation

### Universal Issues (All Platforms)
1. **Docker command syntax**: Use `docker compose` (not `docker-compose`)

2. **WordPress hook timing**: 
   - `plugins_loaded`: Too early for some WordPress functions
   - `init`: Better for most custom functionality
   - `wp_loaded`: After WordPress is fully loaded

3. **WordPress constants**: Only available after appropriate hooks

## Tool Usage Guidelines (All Platforms)

### Command Execution Priority:
1. **First choice**: Use Copilot tools (`grep_search`, `file_search`, `run_in_terminal`, etc.)
2. **Second choice**: Simple Docker commands without pipes
3. **Third choice**: Platform-native commands
   - **macOS/Linux**: Unix commands work directly
   - **Windows**: Use WSL for Unix-style commands (`wsl bash -c "command"`)
4. **Last resort**: Complex platform-specific shell operations

### Before running terminal commands:
1. **Always check if a Copilot tool exists first** (grep_search, file_search, etc.)
2. **For simple commands**: Use `run_in_terminal` tool
3. **For file operations**: Use `read_file`, `replace_string_in_file`, etc.
4. **For searches**: Use `grep_search` or `semantic_search`
5. **If manual terminal needed**: Consider platform differences

### Platform-Aware Development:
- **File paths**: Use absolute paths in tools, forward slashes in code
- **Line endings**: Git should handle automatically (`.gitattributes`)
- **Docker**: Works consistently across platforms
- **WordPress**: Identical behavior in containers

### For WordPress debugging (All Platforms):
1. Use `docker compose exec wordpress` for container commands
2. Debug logs: `/var/www/html/wp-content/debug.log` (same in all containers)
3. Use `run_in_terminal` tool for log checking
4. WordPress hooks work identically across platforms

### For file operations (All Platforms):
1. **Preferred**: Use `read_file` with line ranges
2. **Preferred**: Use `replace_string_in_file` with sufficient context (3-5 lines)
3. **Preferred**: Use `grep_search` instead of manual find/grep
4. **Paths**: Use absolute paths in tools; relative paths in code

## Common Debugging Steps (All Platforms)

1. **Check containers**: `docker compose ps`
2. **Check WordPress logs**: Use `run_in_terminal` with `docker compose exec wordpress tail -n 20 /var/www/html/wp-content/debug.log`
3. **Test URLs**: Use `open_simple_browser` tool
4. **Add debug logging**: Use `error_log()` in PHP code
5. **Search codebase**: Use `grep_search` or `semantic_search` tools

## Last Updated
July 28, 2025 - Updated for cross-platform compatibility (Windows + macOS teams)

## Self-Improvement Protocol

### When Commands Fail (Any Platform):
1. **Document the failure** with platform context
2. **Add the correct command** for the relevant platform(s)
3. **Include platform-specific notes** where needed
4. **Update this section** with lessons learned

### Template for Cross-Platform Command Corrections:
```markdown
#### ❌ WRONG: [Description] (Platform: Windows/macOS/Linux)
```bash
[command that failed]
```

#### ✅ CORRECT: [Description]
**macOS/Linux:**
```bash
[working command for Unix systems]
```

**Windows:**
```powershell
[working command for Windows - may use WSL]
```
- **Why it failed**: [Platform-specific explanation]
- **When to use**: [Context and platform considerations]
```

### Platform Testing Notes:
- **Windows**: PowerShell limitations with pipes and Unix commands
- **macOS**: Case-sensitive filesystem, different default shell (zsh vs bash)
- **Linux**: Permissions considerations, package manager differences
- **Docker**: Should behave identically across all platforms
