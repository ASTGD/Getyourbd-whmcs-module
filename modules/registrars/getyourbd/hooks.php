<?php

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

if (defined('GETYOURBD_HOOKS_REGISTERED')) {
    return;
}
define('GETYOURBD_HOOKS_REGISTERED', true);

require_once __DIR__ . '/lib/bootstrap.php';

use GetYourBd\DomainDataManager;
use WHMCS\Database\Capsule;

add_hook('ShoppingCartValidateDomainsConfig', 1, function ($vars) {
    return DomainDataManager::captureConfiguration((array) $vars);
});

add_hook('AfterShoppingCartCheckout', 1, function ($vars) {
    DomainDataManager::bindCheckout((array) ($vars['DomainIDs'] ?? []));
});

add_hook('ClientAreaFooterOutput', 1, function ($vars) {
    $constrainNameservers = false;
    $domainId = (int) ($_GET['id'] ?? 0);
    if ($domainId > 0) {
        $domain = Capsule::table('tbldomains')->where('id', $domainId)->first(['registrar']);
        $constrainNameservers = $domain && strcasecmp((string) $domain->registrar, 'getyourbd') === 0;
    }

    $webRoot = rtrim((string) ($vars['WEB_ROOT'] ?? ''), '/');
    $uploadUrl = $webRoot . '/modules/addons/getyourbd/upload.php';
    $csrfToken = (string) ($vars['token'] ?? '');
    $uploadUrlJson = json_encode($uploadUrl);
    $csrfTokenJson = json_encode($csrfToken);
    $constrainNameserversJson = json_encode($constrainNameservers);

    return <<<HTML
<script>
(function () {
    "use strict";
    var uploadUrl = {$uploadUrlJson};
    var csrfToken = {$csrfTokenJson};
    var constrainNameservers = {$constrainNameserversJson};

    function closestGroup(element) {
        return element.closest(".form-group,.row,.col-sm-4") || element.parentElement;
    }

    function findFieldInputs(fieldName) {
        var found = [];
        var directSelector = 'input[name$="[' + fieldName + ']"],input[name$="[GetYourBD ' + fieldName + ']"]';
        document.querySelectorAll(directSelector).forEach(function (input) {
            found.push(input);
        });

        if (found.length) {
            return found;
        }

        document.querySelectorAll('input[type="text"],input:not([type])').forEach(function (input) {
            var container = input.parentElement;
            for (var depth = 0; container && depth < 5; depth++, container = container.parentElement) {
                var text = (container.textContent || "").replace(/\s+/g, " ").trim();
                var inputCount = container.querySelectorAll('input[type="text"],input:not([type])').length;
                if (text.indexOf(fieldName) !== -1 && inputCount === 1) {
                    found.push(input);
                    break;
                }
            }
        });

        return found;
    }

    function hideExtraNameservers() {
        [4, 5].forEach(function (index) {
            [
                '[name="domainns' + index + '"]',
                '[name="ns' + index + '"]',
                '[name="nameserver' + index + '"]',
                '#domainns' + index,
                '#ns' + index,
                '#nameserver' + index
            ].forEach(function (selector) {
                var element = document.querySelector(selector);
                if (!element) {
                    return;
                }
                var group = closestGroup(element);
                if (group) {
                    group.style.display = "none";
                }
                element.value = "";
            });
        });
    }

    function requireMinimumNameservers() {
        [1, 2].forEach(function (index) {
            [
                '[name="domainns' + index + '"]',
                '[name="ns' + index + '"]',
                '[name="nameserver' + index + '"]',
                '#domainns' + index,
                '#ns' + index,
                '#nameserver' + index
            ].some(function (selector) {
                var element = document.querySelector(selector);
                if (!element) {
                    return false;
                }
                element.required = true;
                return true;
            });
        });
    }

    function enhanceUpload(fieldName, fieldType, required) {
        findFieldInputs(fieldName).forEach(function (hidden) {
            if (hidden.dataset.getyourbdUpload) {
                return;
            }

            hidden.dataset.getyourbdUpload = "1";
            if (hidden.value.indexOf("getyourbd-upload:") !== 0) {
                hidden.value = "";
            }
            hidden.type = "hidden";

            var file = document.createElement("input");
            file.type = "file";
            file.accept = ".jpg,.jpeg,.png,.pdf";
            file.className = hidden.className || "form-control";
            if (required) {
                file.required = !hidden.value;
            }

            var status = document.createElement("div");
            status.className = "help-block getyourbd-upload-status";
            status.textContent = hidden.value ? "Document uploaded" : "JPG, PNG or PDF; maximum 15 MB";

            file.addEventListener("change", function () {
                if (!file.files.length) {
                    return;
                }

                file.disabled = true;
                status.textContent = "Uploading...";
                var data = new FormData();
                data.append("token", csrfToken);
                data.append("field_type", fieldType);
                data.append("document", file.files[0]);

                fetch(uploadUrl, {method: "POST", body: data, credentials: "same-origin"})
                    .then(function (response) { return response.json(); })
                    .then(function (result) {
                        if (!result.success) {
                            throw new Error(result.error || "Upload failed");
                        }
                        hidden.value = result.reference;
                        file.required = false;
                        status.textContent = result.filename + " uploaded";
                    })
                    .catch(function (error) {
                        hidden.value = "";
                        file.required = required;
                        file.value = "";
                        status.textContent = error.message;
                    })
                    .finally(function () { file.disabled = false; });
            });

            hidden.parentNode.insertBefore(file, hidden.nextSibling);
            hidden.parentNode.insertBefore(status, file.nextSibling);
        });
    }

    function cleanLegacyLabels() {
        var replacements = {
            "GetYourBD NID Full Name:": "NID Full Name:",
            "GetYourBD NID:": "NID:",
            "GetYourBD Contact Number:": "Mobile Number:",
            "GetYourBD NID Document:": "NID Document:",
            "GetYourBD Registration Document:": "Registration Document:"
        };
        document.querySelectorAll("label,.col-sm-4").forEach(function (element) {
            var value = element.textContent.trim();
            if (replacements[value]) {
                element.textContent = replacements[value];
            }
        });
    }

    function init() {
        var hasGetYourBdConfigFields = !!findFieldInputs("NID Document").length;

        if (constrainNameservers || hasGetYourBdConfigFields) {
            hideExtraNameservers();
            requireMinimumNameservers();
        }

        if (!hasGetYourBdConfigFields) {
            return;
        }
        cleanLegacyLabels();
        enhanceUpload("NID Document", "nid", true);
        enhanceUpload("Registration Document", "registration", false);
    }

    if (document.readyState === "loading") {
        document.addEventListener("DOMContentLoaded", init);
    } else {
        init();
    }
})();
</script>
HTML;
});

add_hook('AdminAreaFooterOutput', 1, function ($vars) {
    $filename = pathinfo(strtolower((string) ($vars['filename'] ?? $_SERVER['SCRIPT_NAME'] ?? '')), PATHINFO_FILENAME);
    $domainId = (int) ($_GET['id'] ?? 0);
    if ($filename !== 'clientsdomains' || !$domainId) {
        return '';
    }

    $domain = Capsule::table('tbldomains')->where('id', $domainId)->first(['registrar']);
    if (!$domain || strcasecmp((string) $domain->registrar, 'getyourbd') !== 0) {
        return '';
    }

    $webRoot = rtrim((string) ($vars['WEB_ROOT'] ?? ''), '/');
    if ($webRoot === '') {
        $systemUrlPath = parse_url((string) ($GLOBALS['CONFIG']['SystemURL'] ?? ''), PHP_URL_PATH);
        $webRoot = rtrim(is_string($systemUrlPath) ? $systemUrlPath : '', '/');
    }
    $uploadUrl = $webRoot . '/modules/addons/getyourbd/admin-upload.php';
    $uploadUrlJson = json_encode($uploadUrl);
    $csrfToken = (string) ($vars['token'] ?? '');
    if ($csrfToken === '' && function_exists('generate_token')) {
        $csrfToken = (string) generate_token('plain');
    }
    $csrfTokenJson = json_encode($csrfToken);
    $domainIdJson = json_encode($domainId);

    return <<<HTML
<script>
(function () {
    "use strict";
    var uploadUrl = {$uploadUrlJson};
    var csrfToken = {$csrfTokenJson};
    var domainId = {$domainIdJson};

    function createUploadControl(label, fieldType, required) {
        var group = document.createElement("div");
        group.className = "form-group col-md-6";

        var fieldLabel = document.createElement("label");
        fieldLabel.textContent = label;
        group.appendChild(fieldLabel);

        var input = document.createElement("input");
        input.type = "file";
        input.accept = ".jpg,.jpeg,.png,.pdf";
        input.className = "form-control";
        group.appendChild(input);

        var status = document.createElement("p");
        status.className = "help-block";
        status.textContent = (required ? "Required registry document. " : "Optional supporting document. ") + "JPG, PNG or PDF; maximum 15 MB.";
        group.appendChild(status);

        input.addEventListener("change", function () {
            if (!input.files.length) {
                return;
            }

            input.disabled = true;
            status.className = "help-block text-info";
            status.textContent = "Uploading...";

            var data = new FormData();
            data.append("token", csrfToken);
            data.append("domain_id", String(domainId));
            data.append("field_type", fieldType);
            data.append("document", input.files[0]);

            fetch(uploadUrl, {method: "POST", body: data, credentials: "same-origin"})
                .then(function (response) { return response.json(); })
                .then(function (result) {
                    if (!result.success) {
                        throw new Error(result.error || "Upload failed");
                    }
                    status.className = "help-block text-success";
                    status.textContent = result.filename + " uploaded and saved.";
                    input.value = "";
                })
                .catch(function (error) {
                    status.className = "help-block text-danger";
                    status.textContent = error.message;
                })
                .finally(function () { input.disabled = false; });
        });

        return group;
    }

    function init() {
        if (document.getElementById("getyourbd-admin-documents")) {
            return;
        }

        var host = document.querySelector(".contentarea form, #contentarea form, .main-content form");
        if (!host || !host.parentNode) {
            return;
        }

        var panel = document.createElement("div");
        panel.id = "getyourbd-admin-documents";
        panel.className = "panel panel-default";

        var heading = document.createElement("div");
        heading.className = "panel-heading";
        heading.textContent = "GetYourBD Registration Documents";
        panel.appendChild(heading);

        var body = document.createElement("div");
        body.className = "panel-body";
        var row = document.createElement("div");
        row.className = "row";
        row.appendChild(createUploadControl("NID Document", "nid", true));
        row.appendChild(createUploadControl("Registration Document", "registration", false));
        body.appendChild(row);
        panel.appendChild(body);

        host.parentNode.insertBefore(panel, host);
    }

    if (document.readyState === "loading") {
        document.addEventListener("DOMContentLoaded", init);
    } else {
        init();
    }
})();
</script>
HTML;
});
