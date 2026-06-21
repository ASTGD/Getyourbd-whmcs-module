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

add_hook('ShoppingCartValidateDomainsConfig', 1, function ($vars) {
    return DomainDataManager::captureConfiguration((array) $vars);
});

add_hook('AfterShoppingCartCheckout', 1, function ($vars) {
    DomainDataManager::bindCheckout((array) ($vars['DomainIDs'] ?? []));
});

add_hook('ClientAreaFooterOutput', 1, function ($vars) {
    $webRoot = rtrim((string) ($vars['WEB_ROOT'] ?? ''), '/');
    $uploadUrl = $webRoot . '/modules/addons/getyourbd/upload.php';
    $csrfToken = (string) ($vars['token'] ?? '');
    $uploadUrlJson = json_encode($uploadUrl);
    $csrfTokenJson = json_encode($csrfToken);

    return <<<HTML
<script>
(function () {
    "use strict";
    var uploadUrl = {$uploadUrlJson};
    var csrfToken = {$csrfTokenJson};

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
        ["domainns4", "domainns5"].forEach(function (name) {
            var element = document.querySelector('[name="' + name + '"]');
            if (!element) {
                return;
            }
            var group = closestGroup(element);
            if (group) {
                group.style.display = "none";
            }
            element.value = "";
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
        if (!findFieldInputs("NID Document").length) {
            return;
        }
        hideExtraNameservers();
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
