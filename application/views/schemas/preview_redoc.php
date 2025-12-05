<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?php echo html_escape(isset($schema['title']) && $schema['title'] ? $schema['title'] : $schema['uid']); ?> - Schema Preview</title>
    <style>
        html, body {
            height: 100%;
            margin: 0;
            font-family: "Roboto", Arial, sans-serif;
            background: #fff;
        }
        #redoc-container {
            height: 100%;
        }
    </style>
    <script src="https://cdn.redoc.ly/redoc/latest/bundles/redoc.standalone.js"></script>
</head>
<body>
    <div id="redoc-container"></div>

    <script>
        (function() {
            var specUrl = <?php echo json_encode($spec_url); ?>;
            var compiledUrl = <?php echo json_encode(site_url('api/schemas/compiled_schema/' . rawurlencode($schema['uid']))); ?>;

            if (window){
                window.SchemaPreviewConfig = {
                    compiledSchemaUrl: compiledUrl,
                    fetchCompiledSchema: function() {
                        return fetch(compiledUrl).then(function(response) {
                            if (!response.ok) {
                                throw new Error('Failed to load compiled schema');
                            }
                            return response.json();
                        });
                    }
                };
            }

            function initRedoc() {
                if (!window.Redoc) {
                    return;
                }
                Redoc.init(specUrl, {
                    hideLoading: true,
                    noAutoAuth: true,
                    scrollYOffset: 0,
                    theme: {
                        colors: {
                            primary: {
                                main: '#3f51b5'
                            }
                        }
                    }
                }, document.getElementById('redoc-container'));
            }

            if (document.readyState === 'loading') {
                document.addEventListener('DOMContentLoaded', initRedoc);
            } else {
                initRedoc();
            }
        })();
    </script>
</body>
</html>
