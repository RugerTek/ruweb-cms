/*
 * Fake of bancard-checkout-<version>.js (https://github.com/Bancard/bancard-checkout-js, build 5.0.1) served by
 * tests/Support/FakeBancardServer/router.php for browser QA. Same public surface and behaviour as the real library:
 *
 *   Bancard.Checkout.createForm(divId, processId, options?)  → mounts an iframe on <BANCARD_URL>/checkout/new?process_id=…
 *   options.styles           → sent as ?styles=<urlencoded JSON> (not validated here)
 *   options.responseHandler  → called with the iframe's message instead of redirecting
 *   Bancard.destroy()        → removes the iframe and the listener
 *
 * Messages are accepted only from BANCARD_URL (event.origin). {iframeHeight} resizes the iframe; any other message
 * {message, details, return_url} redirects to return_url?status=<message>&description=<details>.
 * __FAKE_BANCARD_URL__ is replaced by the router with its own origin.
 */
(function (window) {
    'use strict';

    var BANCARD_URL = '__FAKE_BANCARD_URL__';
    var CHECKOUT_URL = BANCARD_URL + '/checkout/new';
    var handler = 'default';
    var listener = null;

    function InvalidParameter(name) {
        this.name = 'InvalidParameter';
        this.message = 'Invalid parameter: ' + name;
        this.stack = (new Error()).stack;
    }
    InvalidParameter.prototype = new Error();

    function DivDoesNotExist(divId) {
        this.name = 'DivDoesNotExist';
        this.message = 'Div does not exist: ' + divId;
        this.stack = (new Error()).stack;
    }
    DivDoesNotExist.prototype = new Error();

    function addParamToUrl(url, name, value) {
        var last = url.slice(-1);
        var param = name + '=' + value;
        if (last === '&' || last === '?') {
            return url + param;
        }

        return url.indexOf('?') > -1 ? url + '&' + param : url + '?' + param;
    }

    function redirect(data) {
        var url = addParamToUrl(data.return_url, 'status', data.message);
        if (data.details !== undefined) {
            url = addParamToUrl(url, 'description', data.details);
        }
        window.location.replace(url);
    }

    function updateMinHeight(height, divId) {
        var iframe = document.querySelectorAll('#' + divId + ' iframe')[0];
        if (iframe) {
            iframe.style.minHeight = height + 'px';
        }
    }

    function responseHandler(event, divId) {
        if (event.origin !== BANCARD_URL) {
            return;
        }
        if (event.data.iframeHeight !== undefined) {
            updateMinHeight(event.data.iframeHeight, divId);
            return;
        }
        if (handler === 'default') {
            redirect(event.data);
        } else {
            handler(event.data);
        }
    }

    function initializeIframe(divId, url, options) {
        if (typeof divId !== 'string' || divId === '') {
            throw new InvalidParameter('Div id');
        }
        var container = window.document.getElementById(divId);
        if (!container) {
            throw new DivDoesNotExist(divId);
        }
        var src = url;
        if (options !== undefined) {
            if (options.styles !== undefined) {
                src = addParamToUrl(src, 'styles', encodeURIComponent(JSON.stringify(options.styles)));
            }
            if (options.responseHandler !== undefined) {
                handler = options.responseHandler;
            }
        }
        var iframe = window.document.createElement('iframe');
        iframe.src = src;
        iframe.style.width = '100%';
        iframe.style.borderWidth = '0px';
        iframe.allow = 'web-share';
        container.innerHTML = '';
        container.appendChild(iframe);
        if (listener) {
            window.removeEventListener('message', listener);
        }
        listener = function (event) {
            responseHandler(event, divId);
        };
        window.addEventListener('message', listener);
    }

    var Bancard = {
        divId: null,
        get Checkout() {
            var self = this;

            return {
                createForm: function (divId, processId, options) {
                    if (typeof processId !== 'string' || processId === '') {
                        throw new InvalidParameter('Process id');
                    }
                    self.divId = divId;
                    initializeIframe(divId, addParamToUrl(CHECKOUT_URL, 'process_id', processId), options);
                }
            };
        },
        destroy: function () {
            var container = window.document.getElementById(this.divId);
            if (listener) {
                window.removeEventListener('message', listener);
                listener = null;
            }
            if (container) {
                while (container.firstChild) {
                    container.removeChild(container.firstChild);
                }
            }
            handler = 'default';
            this.divId = null;
        },
        isFake: true
    };

    window.Bancard = Bancard;
})(window);
