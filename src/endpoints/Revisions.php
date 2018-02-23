<?php

namespace Directus\Api\Routes;

use Directus\Application\Application;
use Directus\Application\Http\Request;
use Directus\Application\Http\Response;
use Directus\Application\Route;
use Directus\Database\TableGateway\DirectusActivityTableGateway;
use Directus\Services\RevisionsService;

class Revisions extends Route
{
    /**
     * @param Application $app
     */
    public function __invoke(Application $app)
    {
        $app->get('/{collection}/{id}', [$this, 'all']);
    }

    /**
     * @param Request $request
     * @param Response $response
     *
     * @return Response
     */
    public function all(Request $request, Response $response)
    {
        $service = new RevisionsService($this->container);
        $responseData = $service->findItemAll(
            $request->getAttribute('collection'),
            $request->getAttribute('id'),
            $request->getQueryParams()
        );

        return $this->responseWithData($request, $response, $responseData);
    }
}
