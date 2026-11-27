# Authentication entry point

Anonymous visitors opening a protected page are redirected to the login page rather than shown a generic authentication error. This applies both to firewall access rules and controller authorization checks.

The main firewall uses `App\Security\LoginEntryPoint`, which generates the `app_login` route. Requests with an `application/json` Accept or Content-Type header, and XMLHttpRequests, retain the identity bundle's JSON 401 response. Signed-in users without the required permissions continue to receive a 403 through the existing access-denied handler.

Login does not automatically return visitors to the originally requested page. Requests that do not identify themselves as JSON or XMLHttpRequest are treated as browser navigation.
