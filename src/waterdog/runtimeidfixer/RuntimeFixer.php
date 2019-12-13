<?php
	namespace waterdog\runtimeidfixer;
	use pocketmine\event\Listener;
	use pocketmine\plugin\PluginBase;
	use pocketmine\network\mcpe\protocol\types\RuntimeBlockMapping;
	use pocketmine\utils\MainLogger;
	use pocketmine\nbt\tag\CompoundTag;
	use pocketmine\network\mcpe\protocol\StartGamePacket;

	use pocketmine\nbt\NetworkLittleEndianNBTStream;
	use pocketmine\nbt\tag\ListTag;
	use pocketmine\utils\BinaryDataException;
	use function file_get_contents;
	
	class RuntimeFixer extends PluginBase implements Listener{

        const NUKKIT_BLOCK_STATES = "https://raw.githubusercontent.com/NukkitX/Nukkit/master/src/main/resources/runtime_block_states.dat";
        const NUKKIT_BLOCK_STATES_OLD = "https://raw.githubusercontent.com/NukkitX/Nukkit/74ff17b98733159e788cb2763ea40886124980fc/src/main/resources/runtime_block_states.dat";
		
		public function onEnable () {
			$this->init();
		}
		
		public function init() : void{
			try {
				$tag = null;
				$file = null;
				try{
				    $file = file_get_contents(self::NUKKIT_BLOCK_STATES, false, stream_context_create([
                        "ssl" => [
                            "verify_peer" => false,
                            "verify_peer_name" => false,
                        ]
                    ]));

					/** @var ListTag $tag */
					$tag = (new NetworkLittleEndianNBTStream())->read($file);
				}catch(BinaryDataException $e){
					throw new RuntimeException("", 0, $e);
				}
                
                $blockReflect = new \ReflectionClass(RuntimeBlockMapping::class);
                
                $legacyToRuntimeMap = $blockReflect->getProperty("legacyToRuntimeMap");
                $legacyToRuntimeMap->setAccessible(true);

                $runtimeToLegacyMap = $blockReflect->getProperty("runtimeToLegacyMap");
                $runtimeToLegacyMap->setAccessible(true);

                $bedrockKnownStates = $blockReflect->getProperty("bedrockKnownStates");
                $bedrockKnownStates->setAccessible(true);

                $registerMapping = $blockReflect->getMethod("registerMapping");
                $registerMapping->setAccessible(true);

                $randomizeTable = $blockReflect->getMethod("randomizeTable");
                $randomizeTable->setAccessible(true);



                /* Null values first*/
                $runtimeToLegacyMap->setValue([]);
                $legacyToRuntimeMap->setValue([]);
                $bedrockKnownStates->setValue($tag->getValue());

                /** @var CompoundTag $state */
                $decompressed = [];

                $runtimeIdAllocator = 0;
                $runtimeToLegacy = [];
                $legacyToRuntime = [];

                foreach($tag->getAllValues() as $state){
                    $runtimeId = $runtimeIdAllocator;
                    $runtimeIdAllocator++;

                    $block = $state->getCompoundTag("block");
                    $id = $state->getShort("id");
                    $name = $block->getString("name");

                    $meta = [0];
                    if ($state->hasTag("meta")){
                        $meta = $state->getIntArray("meta");
                        $state->removeTag("meta");
                    }

                    /* Save data cor feature references*/
                    $decompressed[$runtimeId] = [
                        "name" => $name,
                        "states" => $block->getCompoundTag("states"),
                        "data" => $meta[0],
                        "legacy_id" => $id
                    ];

                    $runtimeToLegacy[$runtimeId] = $id << 4 | $meta[0];
                    foreach ($meta as $val){
                        $legacyId = $id << 4 | $val;
                        $legacyToRuntime[$legacyId] = $runtimeId;
                    }
                }

                //$bedrockKnownStates->setValue($decompressed);
                $runtimeToLegacyMap->setValue($runtimeToLegacy);
                $legacyToRuntimeMap->setValue($legacyToRuntime);

                $startGameReflect = new \ReflectionClass(StartGamePacket::class);
                $blockTableCache = $startGameReflect->getProperty("blockTableCache");
                $blockTableCache->setAccessible(true);
                $blockTableCache->setValue($file);
			}catch (\ReflectionException $e) {
				MainLogger::getLogger()->logException($e);
			}
		}
	}
