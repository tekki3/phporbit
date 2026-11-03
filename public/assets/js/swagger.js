"use strict";

SwaggerUIBundle({
    url: "/api/openapi.json",
    dom_id: "#swagger-ui",
    deepLinking: true,
    validatorUrl: null,
    persistAuthorization: false,
    withCredentials: false,
    supportedSubmitMethods: ["get", "post", "put", "patch", "delete"],
    requestInterceptor: function (request) {
        request.credentials = "omit";
        return request;
    }
});
