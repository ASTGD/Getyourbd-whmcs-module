<?php

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

require_once __DIR__ . '/lib/bootstrap.php';

use GetYourBd\DomainDataManager;

add_hook('ShoppingCartValidateDomainsConfig', 1, function ($vars) {
    return DomainDataManager::captureConfiguration((array) $vars);
});

add_hook('AfterShoppingCartCheckout', 1, function ($vars) {
    DomainDataManager::bindCheckout((array) ($vars['DomainIDs'] ?? []));
});

add_hook('ClientAreaFooterOutput', 1, function ($vars) {
    if (($vars['filename'] ?? '') !== 'cart') {
        return '';
    }

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
        var selector = 'input[name$="[' + fieldName + ']"],input[name$="[GetYourBD ' + fieldName + ']"]';
        document.querySelectorAll(selector).forEach(function (hidden) {
            if (hidden.dataset.getyourbdUpload) {
                return;
            }

            hidden.dataset.getyourbdUpload = "1";
            hidden.type = "hidden";

            var file = document.createElement("input");
            file.type = "file";
            file.accept = ".jpg,.jpeg,.png,.pdf";
            file.className = "form-control";
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
        var relevant = document.querySelector('input[name$="[NID Document]"],input[name$="[GetYourBD NID Document]"]');
        if (!relevant) {
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
