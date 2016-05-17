<?php
/**
 * Copyright 2016 François Kooman <fkooman@tuxed.net>.
 *
 * Licensed under the Apache License, Version 2.0 (the "License");
 * you may not use this file except in compliance with the License.
 * You may obtain a copy of the License at
 *
 * http://www.apache.org/licenses/LICENSE-2.0
 *
 * Unless required by applicable law or agreed to in writing, software
 * distributed under the License is distributed on an "AS IS" BASIS,
 * WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied.
 * See the License for the specific language governing permissions and
 * limitations under the License.
 */

namespace fkooman\VPN\Server\CnConfig;

use fkooman\VPN\Server\InputValidation;

class CnConfig
{
    /** @var bool */
    private $disable;

    public function __construct(array $configData)
    {
        $this->setDisable(
            array_key_exists('disable', $configData) ? $configData['disable'] : false
        );
    }

    public function getDisable()
    {
        return $this->disable;
    }

    public function setDisable($disable)
    {
        InputValidation::disable($disable);
        $this->disable = $disable;
    }

    public function toArray()
    {
        return [
            'disable' => $this->disable,
        ];
    }
}
