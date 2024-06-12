<?php


namespace App\Service;

use App\Entity\ChatRoom;
use App\Entity\Company;
use App\Entity\DailyOrder;
use App\Entity\DailyOrderDetail;
use App\Entity\GracePeriod;
use App\Entity\Module;
use App\Entity\Order;
use App\Entity\OrderAttachment;
use App\Entity\OrderComponentDetail;
use App\Entity\OrderDetail;
use App\Entity\OrderPreferences;
use App\Entity\User;
use App\Repository\DailyOrderDetailRepository;
use App\Repository\DailyOrderRepository;
use App\Repository\DishRepository;
use App\Repository\GracePeriodRepository;
use App\Repository\ModuleComponentRepository;
use App\Repository\ModuleRepository;
use App\Repository\OrderComponentDetailRepository;
use App\Repository\OrderDetailRepository;
use App\Repository\OrderPreferencesRepository;
use App\Repository\OrderRatingRepository;
use App\Repository\OrderRepository;
use App\Repository\PublicHolidayRepository;
use App\Repository\RoleRepository;
use App\Repository\UserDishPreferenceRepository;
use App\Repository\UserRepository;
use App\Repository\WorkingDayRepository;
use App\Trait\DateTimeTrait;
use App\Trait\RatingTrait;
use DateTime;
use Doctrine\ORM\EntityManagerInterface;
use GraphQL\Error\Error;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

class OrderService
{
    use DateTimeTrait;
    use RatingTrait;
    private EntityManagerInterface $manager;
    private UserRepository $userRepo;
    private OrderRepository $orderRepo;
    private RoleRepository $roleRepo;
    private ModuleRepository $moduleRepo;
    private DishRepository $dishRepo;
    private OrderDetailRepository $orderDetailRepo;
    private FileUploader $fileUploader;
    private GracePeriodRepository $graceRepo;
    private WorkingDayRepository $workingDayRepo;
    private DailyOrderRepository $dailyOrderRepo;
    private DailyOrderDetailRepository $dailyOrderDetailRepo;
    private PublicHolidayRepository $publicHolidayRepo;
    private OrderRatingRepository $orderRatingRepository;
    private ChatRoomService $chatRoomService;
    private UserDishPreferenceRepository $userDishPreferenceRepository;
    private OrderPreferencesRepository $orderPreferencesRepository;
    private TokenStorageInterface $tokenStorageInterface;
    private TranslatorInterface $ti;
    private WorkingDayService $workingDayService;
    private OrderComponentDetailRepository $orderComponentDetailRepository;
    private ModuleComponentRepository $moduleComponentRepository;
    private NotificationService $notificationService;

    private $statuses;
    private $local;
    private $mailer;


    public function __construct(ModuleComponentRepository $moduleComponentRepository, OrderComponentDetailRepository $orderComponentDetailRepository, NotificationService $notificationService, WorkingDayService $workingDayService, TranslatorInterface $ti, TokenStorageInterface $tokenStorageInterface, EntityManagerInterface $manager, PublicHolidayRepository $publicHolidayRepo, DailyOrderDetailRepository $dailyOrderDetailRepo, DailyOrderRepository $dailyOrderRepo, GracePeriodRepository $graceRepo, WorkingDayRepository $workingDayRepo, OrderDetailRepository $orderDetailRepo, DishRepository $dishRepo, UserRepository $userRepo, RoleRepository $roleRepo, ModuleRepository $moduleRepo, OrderRepository $orderRepo,FileUploader $fileUploader, ParameterBagInterface $parameterBag, OrderRatingRepository $orderRatingRepository, ChatRoomService $chatRoomService,UserDishPreferenceRepository $userDishPreferenceRepository, OrderPreferencesRepository $orderPreferencesRepository, MailerInterface $mailer)

    {
        $this->manager = $manager;
        $this->userRepo = $userRepo;
        $this->roleRepo = $roleRepo;
        $this->moduleRepo = $moduleRepo;
        $this->orderRepo = $orderRepo;
        $this->dishRepo = $dishRepo;
        $this->orderDetailRepo = $orderDetailRepo;
        $this->fileUploader = $fileUploader;
        $this->graceRepo = $graceRepo;
        $this->workingDayRepo = $workingDayRepo;
        $this->dailyOrderRepo = $dailyOrderRepo;
        $this->dailyOrderDetailRepo = $dailyOrderDetailRepo;
        $this->publicHolidayRepo = $publicHolidayRepo;
        $this->orderRatingRepository = $orderRatingRepository;
        $this->chatRoomService = $chatRoomService;
        $this->userDishPreferenceRepository = $userDishPreferenceRepository;
        $this->userDishPreferenceRepository = $userDishPreferenceRepository;
        $this->orderPreferencesRepository = $orderPreferencesRepository;
        $this->tokenStorageInterface = $tokenStorageInterface;
        $this->workingDayService = $workingDayService;
        $this->orderComponentDetailRepository = $orderComponentDetailRepository;
        $this->moduleComponentRepository = $moduleComponentRepository;
        $this->notificationService = $notificationService;

        $this->ti = $ti;
        $this->mailer = $mailer;

        $this->statuses = require $parameterBag->get('app.statuses');
        $local = null;
        if(@$tokenStorageInterface->getToken())
        {
            $local = $tokenStorageInterface->getToken()->getUser()->getLanguage();
        }

        $this->local = $local ? $local : 'en';

    }

    public function translator($key, $options = [])
    {
        return $this->ti->trans($key, $options,'translation',$this->local);
    }

    public function dateValidation($data): void
    {
        $module = $this->moduleRepo->find($data['module_id']);

        $startDate = $data['from_date'];
        $endDate = @$data['to_date'];

        if (empty($startDate)) {
            throw new Error($this->translator('Start date is mandatory'));
        }

        if (isset($data['is_trial']) && $data['is_trial'] == true && empty($endDate))
        {
            throw new Error($this->translator('End date is mandatory'));
        }

        if ($module->getParent()) {
            $this->validateOrdersForParentModule($data, $startDate, $endDate);
        } else {

            $this->validateOrdersForSingleModule($data, $startDate, $endDate);

        }
    }

    private function validateOrdersForSingleModule($data, $startDate, $endDate): void
    {
        $filterData = [
            'company' => $data['company_id'],
            'module' => $data['module_id'],
            'no_of_visits_per_week' => null, // component base false
            'date' => $this->getDateTime($startDate)
        ];

        if (!empty($data['id'])) {
            $filterData['exclude_params']['id'] = $data['id'];
        }
        $orders = $this->orderRepo->filteringData(null, $filterData);

        if (!empty($orders)) {
            throw new Error($this->translator('order_exists_in_date_range', [
                '%date%' => $startDate,
            ]));
        }

        if (!empty($endDate)) {
            $filterData['date'] = $this->getDateTime($endDate);
            $orders = $this->orderRepo->filteringData(null, $filterData);

            if (count($orders) > 0) {
                throw new Error($this->translator('order_exists_in_date_range', [
                    '%date%' => $endDate,
                ]));
            }
        }
    }

    private function validateOrdersForParentModule($data, $startDate, $endDate): void
    {
        if (empty($startDate) || empty($endDate)) {
            throw new Error($this->translator('Both start date and end date are mandatory.'));
        }

        $filterData = [
            'company' => $data['company_id'],
            'vendor' => $data['vendor_id'],
            'module' => 1,
            'date_range' => [
                'from_date' => $this->getDateTime($startDate),
                'to_date' => $this->getDateTime($endDate)
            ]
        ];
        $this->getActiveOrder(null, $filterData);

    }

    public function dishArrValidation($orderDetails) : void
    {
        $dishes_arr = $orderDetails['dishes_arr'];
        $module = $this->moduleRepo->find($orderDetails['module_id']);

        foreach ($dishes_arr as $detail)
        {
            if ($module->getParent() == null)
            {
                $this->validateDish($detail['dish_id']);
            }
            else
            {
                $dish_arr = $detail['dishes'];
                foreach ($dish_arr as $dish_data)
                {
                    $this->validateDish($dish_data['dish_id']);
                }
            }
        }
    }

    private function validateDish($dish_id) : void
    {
        $dish = $this->dishRepo->find($dish_id);
        if (!$dish)
        {
            throw new Error($this->translator('dish_not_found', [
                '%dish_id%' => $dish_id,
            ]));
        }
    }


    public function requestValidations($orderDetails) : void
    {
        // if($orderDetails['module_id'] == 2 || $orderDetails['module_id'] == 4){
        //     $this->checkKitchenAvailibility($orderDetails);
        // }
        $this->dateValidation($orderDetails);
        $this->dishArrValidation($orderDetails);

    }

    public function checkKitchenAvailibility($orderDetails){
        $startDate = $this->getDateTime($orderDetails['from_date']);
        $endDate = $this->getDateTime($orderDetails['to_date']);

        $filterData = [
            'company' => $orderDetails['company_id'],
            'vendor' => $orderDetails['vendor_id'],
            'module' => 1
        ];

        for ($date = clone $startDate; $date <= $endDate; $date->modify('+1 day')) {
            $filterData['date'] = $date;
            $orders = $this->orderRepo->filteringData(null,$filterData);
            if (count($orders) === 0) {
                throw new Error($this->translator('No active kitchen found for selected dates'));
            }
        }
        return true;
    }

    public function checkDishesToBeRemoved($order,$orderDetails)
    {
        $existing_order_details = $order->getOrderDetails();
        $dish_arr = [];
        foreach ($orderDetails['dishes_arr'] as $detail)
        {
            $dish_arr[] = $detail['dish_id'];
        }
        foreach ($existing_order_details as $existing_order_detail)
        {
            if (!in_array($existing_order_detail->getDish()->getId(),$dish_arr))
            {
                $this->manager->remove($existing_order_detail);
                $this->manager->flush();
            }
        }
    }

    public function saveOrderDetail($order, $orderDetails)
    {
        if (isset($orderDetails['id']))
        {
            $this->checkDishesToBeRemoved($order,$orderDetails);
        }

        foreach ($orderDetails['dishes_arr'] as $detail)
        {
            $this->createOrUpdateOrderDetail($order,$detail);
        }
        $this->manager->flush();
    }

    public function saveOrder($orderDetails)
    {
        $module = $this->moduleRepo->find($orderDetails['module_id']);

        if (isset($orderDetails['dishes_arr']) || isset($orderDetails['date_dishes_arr']))
        {
            $orderDetails['dishes_arr'] = @$orderDetails['dishes_arr']?:$orderDetails['date_dishes_arr'];
            $this->requestValidations($orderDetails);

            /*save order information*/
            $order = $this->createOrUpdateOrder($orderDetails);

            if ($module->getParent())
            {
                $this->deleteUnusedDailyOrders($order, $orderDetails);
                /*meeting or guest save dayily orders only*/
                $this->saveRangeOrders($order, $orderDetails);          /*save orders based on start and end date*/
            }
            else
            {
                $this->saveOrderDetail($order,$orderDetails);   /*save Monday to Sunday Values (not save in case of meeting and guest)*/
            }


        }

        if (isset($orderDetails['component_arr']))
        {
            // validations
            $this->validateVisits($orderDetails['no_of_visits_per_day'], $orderDetails['no_of_visits_per_week']);

            /*save order information*/
            $order = $this->createOrUpdateOrder($orderDetails);

            $this->saveOrderComponentDetail($order,$orderDetails['component_arr']);
            // save no of visits per day info
            $this->createOrUpdateOrderDetail($order,$orderDetails['no_of_visits_per_day']);

        }

        $this->manager->refresh($order);

        $update = isset($orderDetails['id']);
        $this->notificationService->sendOrderCreatedEmail($order, $update);

        return $order;
    }

    public function validateVisits($no_of_visits_per_day, $no_of_visits_per_week) {
        $total_visits = 0;
        // Loop through each day and sum up the values
        foreach ($no_of_visits_per_day as $day => $visits) {
            $total_visits += $visits;
        }
        if ($total_visits !== $no_of_visits_per_week)
        {
            throw new Error($this->translator('total_visit_not_matched', [
                '%total_visits%' => $total_visits,
                '%no_of_visits_per_week%' => $no_of_visits_per_week,
            ]));
        }
    }

    public function createOrUpdateOrder($orderDetails) :Order
    {
        $order = new Order();
        if (isset($orderDetails['id']))
        {
            $order = $this->find($orderDetails['id']);
        }

        return $this->setOrderEntityProperties($order, $orderDetails);   /*save order information*/
    }

    public function createOrUpdateOrderDetail($order, $detail)
    {
        $dish = null;

        if (isset($detail['id']))
        {
            $entity = $this->orderDetailRepo->find($detail['id']);
            if (!$entity)
            {
                $entity = new OrderDetail();
            }
        }
        else if (isset($detail['dish_id']))
        {
            $dish = $this->dishRepo->find($detail['dish_id']);

            $entity = $this->orderDetailRepo->findOneBy([
                'order'=> $order,
                'dish' => $dish
            ]);

            if (!$entity)
            {
                $entity = new OrderDetail();
            }
        }
        else
        {
            $entity = new OrderDetail();
            // no of visit per day case for other services (component base)
            if (!isset($detail['dish_id']))
            {
                $entity = $this->orderDetailRepo->findOneBy([
                    'order' => $order
                ]);

                if (!$entity)
                {
                    $entity = new OrderDetail();
                }
            }

        }


        $entity->setOrder($order);
        $entity->setDish($dish);
        $entity->setMondayHeads($detail['monday_heads']);
        $entity->setTuesdayHeads($detail['tuesday_heads']);
        $entity->setWednesdayHeads($detail['wednesday_heads']);
        $entity->setThursdayHeads($detail['thursday_heads']);
        $entity->setFridayHeads($detail['friday_heads']);
        $entity->setSaturdayHeads($detail['saturday_heads']);
        $entity->setSundayHeads($detail['sunday_heads']);
        $this->manager->persist($entity);

        $this->manager->flush();
    }

    public function saveOrderComponentDetail($order, $data_array)
    {
        foreach ($data_array as $data)
        {
            $this->createOrUpdateOrderComponentDetail($order,$data);
        }

    }

    public function createOrUpdateOrderComponentDetail($order, $data)
    {
        $moduleComponent = $this->moduleComponentRepository->find($data['module_component_id']);

        if (isset($data['id']))
        {
            $entity = $this->orderComponentDetailRepository->find($data['id']);
        }
        else
        {
            $entity = $this->orderComponentDetailRepository->findOneBy([
                'order' => $order,
                'module_component' => $moduleComponent
            ]);

            if (!$entity)
            {
                $entity = new OrderComponentDetail();
            }
        }
        $entity->setOrder($order);
        $entity->setModuleComponent($moduleComponent);
        $entity->setValue($data['value']);

        $this->manager->persist($entity);
        $this->manager->flush();

        $this->manager->refresh($order);

        return $order;

    }

    public function saveRangeOrders(Order $order, $orderDetails) :void
    {
        foreach ($orderDetails['dishes_arr'] as $detail)
        {
            $date =  $this->getDateTime($detail['date']);
            $dish_arr = $detail['dishes'];

            $daily_order = $this->getOrCreateDailyOrder($order, $date, $dish_arr);

            $daily_order = $this->setDailyOrderEntityProperties($daily_order,$order,$date);

            $total_heads = 0;
            foreach ($dish_arr as $dish_data)
            {
                /**todo
                 * make reuseable function
                 */
                $heads = $dish_data['heads'];
                $dishEntity = $this->dishRepo->find($dish_data['dish_id']);

                $total_heads = $total_heads + $heads;

                $daily_order_detail = $this->dailyOrderDetailRepo->findOneBy([
                    'dish'=> $dishEntity,
                    'daily_order'=>$daily_order
                ]);
                if (!$daily_order_detail)
                {
                    $daily_order_detail = new DailyOrderDetail();
                }
                $daily_order_detail->setNoOfHeads($heads);
                $daily_order_detail->setDish($dishEntity);
                $daily_order_detail->setDailyOrder($daily_order);
                $this->manager->persist($daily_order_detail);
            }
            $daily_order->setTotalHeads($total_heads);
            $this->manager->persist($daily_order);
            $this->manager->flush();
        }
    }

    private function getOrCreateDailyOrder(Order $order, $date,$dish_arr): DailyOrder
    {
        $daily_order = $this->dailyOrderRepo->findOneBy([
            'order' => $order,
            'order_date' => $date
        ]);
        if (!$daily_order)
        {
            $daily_order = new DailyOrder();
        }
        else
        {
            $dishIds = array_column(array_map(function($item) {
                return ['dish_id' => $item['dish_id']];
            }, $dish_arr), 'dish_id');

            foreach ($daily_order->getDailyOrderDetails() as $existing_order_detail)
            {
                if (!in_array($existing_order_detail->getDish()->getId(),$dishIds))
                {
                    $this->manager->remove($existing_order_detail);
                    $this->manager->flush();
                }
            }
        }
        return $daily_order;
    }

    // need to remove
    public function saveOrderOld($orderDetails)
    {
        if (isset($orderDetails['id']))
        {
            $order = $this->find($orderDetails['id']);

            $existing_order_details = $order->getOrderDetails();
            $dish_arr = [];
            foreach ($orderDetails['dishes_arr'] as $detail)
            {
                $dish_arr[] = $detail['dish_id'];
            }
            foreach ($existing_order_details as $existing_order_detail)
            {
                if (!in_array($existing_order_detail->getDish()->getId(),$dish_arr))
                {
                    $this->manager->remove($existing_order_detail);
                    $this->manager->flush();
                }
            }
        }
        else
        {
            $order = new Order();
            $order->setStatus(0);
        }

        $order->setTrial($this->setTrial($orderDetails));
        $company = $this->userRepo->find($orderDetails['company_id']);
        $order->setCompany($company);
        $vendor = $this->userRepo->find($orderDetails['vendor_id']);
        $order->setVendor($vendor);
        $module = $this->moduleRepo->find($orderDetails['module_id']);
        $order->setModule($module);
        if (@$orderDetails['from_date'])
        {
            $fromDate = new \DateTime($orderDetails['from_date']);
            $order->setFromDate($fromDate);
        }
        else
        {
            $order->setFromDate(null);
        }
        if (@$orderDetails['to_date'])
        {
            $toDate = new \DateTime($orderDetails['to_date']);
            $order->setToDate($toDate);
        }
        else
        {
            $order->setToDate(null);
        }

        if (@$orderDetails['no_of_heads'])
        {
            $order->setNoOfHeads($orderDetails['no_of_heads']);
        }
        if (@$orderDetails['additional_notes'])
        {
            $order->setAdditionalNotes($orderDetails['additional_notes']);
        }
        if (@$orderDetails['deliver_with_daily_order'] !== null)
        {
            $order->setDeliverWithDailyOrder($orderDetails['deliver_with_daily_order']);
        }
        $this->manager->persist($order);
        $this->manager->flush();

        foreach ($orderDetails['dishes_arr'] as $detail)
        {
            $dish = $this->dishRepo->find($detail['dish_id']);
            if (!$dish)
            {
                throw new Error($this->translator('dish_not_found', [
                    '%dish_id%' => $detail['dish_id'],
                ]));
            }
            if (@$orderDetails['id'])
            {
                $new_detail = $this->findOrderDetailByOrderIdAndDishId($orderDetails['id'],$detail['dish_id']);
                if (!$new_detail)
                {
                    $new_detail = new OrderDetail();
                }
            }
            else
            {
                $new_detail = new OrderDetail();
            }
            $new_detail->setOrder($order);
            $new_detail->setDish($dish);
            $new_detail->setMondayHeads($detail['monday_heads']);
            $new_detail->setTuesdayHeads($detail['tuesday_heads']);
            $new_detail->setWednesdayHeads($detail['wednesday_heads']);
            $new_detail->setThursdayHeads($detail['thursday_heads']);
            $new_detail->setFridayHeads($detail['friday_heads']);
            $new_detail->setSaturdayHeads($detail['saturday_heads']);
            $new_detail->setSundayHeads($detail['sunday_heads']);
            $this->manager->persist($new_detail);
        }
        $this->manager->flush();


        if ($orderDetails['module_id'] == 2 || $orderDetails['module_id'] == 4) /*For Meeting and Guest*/
        {
            $this->createPerDayGuestOrders($order);
        }
        else if ($orderDetails['module_id'] == 1 || $orderDetails['module_id'] == 3) /*For Lunch and Fruit*/
        {
            $this->createPerDayOrders($order);
        }
        return $order;
    }
    // need to remove
    public function saveModuleOrder($orderDetails)
    {
        // Validate start and end dates
        $startDate = $orderDetails['from_date'];
        $endDate = $orderDetails['to_date'];

        if (empty($startDate) || empty($endDate)) {
            throw new Error($this->translator('Both start date and end date are mandatory.'));
        }

        // Filter data
        $filterData = [
            'company' => $orderDetails['company_id'],
            'vendor' => $orderDetails['vendor_id'],
            'module' => 1,
            'date_range' => [
                'from_date' => $this->getDateTime($startDate),
                'to_date' => $this->getDateTime($endDate)
            ]
        ];
        $orders = $this->orderRepo->filteringData(null,$filterData);
        if (count($orders) === 0) {
            throw new Error($this->translator('no_orders_in_date_range', [
                '%startDate%' => $startDate,
                '%endDate%' => $endDate,
            ]));
        }

        if (isset($orderDetails['id']))
        {
            $order = $this->find($orderDetails['id']);
            $this->deleteUnusedDailyOrders($order, $orderDetails);
        }
        else
        {
            $order = new Order();
            $order->setStatus(0);
        }

        $order = $this->setOrderEntityProperties($order,$orderDetails);

        foreach ($orderDetails['dishes_arr'] as $detail)
        {
            $date =  $this->getDateTime($detail['date']);
            $dish_arr = $detail['dishes'];

            $daily_order = $this->dailyOrderRepo->findOneBy([
                'order' => $order,
                'order_date' => $date
            ]);

            // dd($detail);
            if (!$daily_order)
            {
                $daily_order = new DailyOrder();
            }
            else
            {
                $dishIds = array_column(array_map(function($item) {
                    return ['dish_id' => $item['dish_id']];
                }, $dish_arr), 'dish_id');

                foreach ($daily_order->getDailyOrderDetails() as $existing_order_detail)
                {
                    if (!in_array($existing_order_detail->getDish()->getId(),$dishIds))
                    {
                        $this->manager->remove($existing_order_detail);
                        $this->manager->flush();
                    }
                }
            }
            $daily_order = $this->setDailyOrderEntityProperties($daily_order,$order,$date);

            $total_heads = 0;
            foreach ($dish_arr as $dish_data)
            {
                // dd($dish);
                $heads = $dish_data['no_of_heads'];
                $dishEntity = $this->dishRepo->find($dish_data['dish_id']);

                $total_heads = $total_heads + $heads;

                $daily_order_detail = $this->dailyOrderDetailRepo->findOneBy([
                    'dish'=> $dishEntity,
                    'daily_order'=>$daily_order
                ]);
                if (!$daily_order_detail)
                {
                    $daily_order_detail = new DailyOrderDetail();
                }
                $daily_order_detail->setNoOfHeads($heads);
                $daily_order_detail->setDish($dishEntity);
                $daily_order_detail->setDailyOrder($daily_order);
                $this->manager->persist($daily_order_detail);
            }
            $daily_order->setTotalHeads($total_heads);
            $this->manager->persist($daily_order);
            $this->manager->flush();
        }
        return $order;
    }

    private function deleteUnusedDailyOrders($order, $orderDetails)
    {
        $dateArr = array_map(function($detail) {
            return $detail['date'];
        }, $orderDetails['dishes_arr']);

        $existingDailyOrders = $order->getDailyOrders();

        foreach ($existingDailyOrders as $existingDailyOrder) {
            if (!in_array($existingDailyOrder->getOrderDate(), $dateArr)) {
                $this->deleteDailyOrder($existingDailyOrder);
            }
        }
    }

    private function deleteDailyOrder($dailyOrder)
    {
        $dailyOrderDetails = $dailyOrder->getDailyOrderDetails();

        foreach ($dailyOrderDetails as $dailyOrderDetail) {
            $this->manager->remove($dailyOrderDetail);
        }

        $this->manager->remove($dailyOrder);
        $this->manager->flush();
    }

    public function setOrderEntityProperties($order,$orderDetails)
    {
        $order->setTrial($this->setTrial($orderDetails));
        $company = $this->userRepo->find($orderDetails['company_id']);
        $order->setCompany($company);
        $vendor = $this->userRepo->find($orderDetails['vendor_id']);
        $order->setVendor($vendor);
        $module = $this->moduleRepo->find($orderDetails['module_id']);
        $order->setModule($module);

        $order->setFromDate($this->getDateTime(@$orderDetails['from_date']));
        $order->setToDate($this->getDateTime(@$orderDetails['to_date']));

        $order->setNoOfHeads(@$orderDetails['no_of_heads']?:null);
        $order->setAdditionalNotes(@$orderDetails['additional_notes']?:null);
        $order->setDeliverWithDailyOrder(@$orderDetails['deliver_with_daily_order']?:null);
        $order->setDeliveryTime($this->getDateTime(@$orderDetails['delivery_time']));
        $order->setNoOfVisitsPerWeek(@$orderDetails['no_of_visits_per_week']);

        $this->manager->persist($order);
        $this->manager->flush();

        // $chat_room = $this->createChatRoom($orderDetails['company_id'], $orderDetails['vendor_id']);

        //logic for creating chatRoom
        $admins = $this->userRepo->findBy(['role' => 1]);
        foreach ($admins as $admin)
        {
            $data['room_type'] = 'company-vendor';
            $data['client_id'] = $orderDetails['company_id'];
            $data['vendor_id'] = $orderDetails['vendor_id'];
            $data['admin_id'] = $admin->getId();

            $chat_room = $this->chatRoomService->createChatRoom($data);
        }

        return $order;
    }

    public function setDailyOrderEntityProperties($daily_order,$order,$date)
    {
        $daily_order->setOrder($order);
        $daily_order->setStatus(0);
        $daily_order->setCompany($order->getCompany());
        $daily_order->setVendor($order->getVendor());
        $daily_order->setTotalHeads(0);
        $daily_order->setOrderDate($date);
        $daily_order->setUpdates(0);
        $this->manager->persist($daily_order);
        $this->manager->flush();

        return $daily_order;
    }

    public function createPerDayGuestOrders(Order $order)
    {
        $company = $order->getCompany();
        $working_days = $company->getWorkingDay();
        $start_date = $order->getFromDate();
        $start_date = \DateTime::createFromFormat('Y-m-d', $start_date); // Convert to DateTime object
        $end_date = $order->getToDate();
        $end_date = \DateTime::createFromFormat('Y-m-d', $end_date); // Convert to DateTime object
        $currentDate = $start_date; // Clone the start date to keep track of the loop
        $dayInterval = new \DateInterval('P1D'); // 1 day interval
        while ($currentDate <= $end_date) {
            $dayOfWeek = $currentDate->format('N');
            $this->weekdaysChecks($currentDate,$company,$dayOfWeek,$working_days,$order);
            $currentDate->add($dayInterval); // Move to the next day
        }
    }

    public function weekdaysChecks($currentDate, $company, $dayOfWeek, $working_days, $order)
    {
        if ($this->checkHoliday($currentDate,$company))
        {
            if ($dayOfWeek == 1 && $working_days->isMonday())
            {
                $this->createDailyOrder($currentDate, $order);
            }

            if ($dayOfWeek == 2 && $working_days->isTuesday())
            {
                $this->createDailyOrder($currentDate, $order);
            }

            if ($dayOfWeek == 3 && $working_days->isWednesday())
            {
                $this->createDailyOrder($currentDate, $order);
            }

            if ($dayOfWeek == 4 && $working_days->isThursday())
            {
                $this->createDailyOrder($currentDate, $order);
            }

            if ($dayOfWeek == 5 && $working_days->isFriday())
            {
                $this->createDailyOrder($currentDate, $order);
            }

            if ($dayOfWeek == 6 && $working_days->isSaturday())
            {
                $this->createDailyOrder($currentDate, $order);
            }

            if ($dayOfWeek == 7 && $working_days->isSunday())
            {
                $this->createDailyOrder($currentDate, $order);
            }
        }

    }

    public function findOrderDetailByOrderIdAndDishId(int $orderId, int $dishId)
    {
        return $this->orderDetailRepo->createQueryBuilder('od')
            ->join('od.order', 'o')
            ->join('od.dish', 'd')
            ->where('o.id = :orderId')
            ->andWhere('d.id = :dishId')
            ->setParameter('orderId', $orderId)
            ->setParameter('dishId', $dishId)
            ->getQuery()
            ->getOneOrNullResult();
    }

    public function getOrderPerModule($company_id, $module_id)
    {
        $company = $this->userRepo->find($company_id);
        $module = $this->moduleRepo->find($module_id);
        return $this->orderRepo->findBy(['company'=>$company,'module'=>$module]);
    }

    public function findUsersPerOrders(?string $keyword, ?array $key_search = [], ?string $user_type = null, ?int $limit = null, ?int $page = null)
    {
        // Get the current user companies
        $key_search = $this->getAuthCompanies($key_search);

        // Retrieve filtered orders based on provided parameters
        $result = $this->orderRepo->filteringData($keyword, $key_search, $limit, $page);

        // Initialize variables for storing unique users and total employee count
        $uniqueUsers = [];
        $total_employees = 0;

        // Determine the getter method based on user_type ('vendor' or 'company')
        $getterMethodName = $user_type === 'vendor' ? 'getVendor' : ($user_type === 'company' ? 'getCompany' : null);

        // If 'company' key is set in $key_search, retrieve and count its employees
        if (!empty($key_search['company'])) {
            $company = $this->userRepo->find($key_search['company']);
            if ($company) {
                $total_employees = $company->getChildren()->count();
            }
        }

        // Process each order to extract unique users based on user type
        if ($getterMethodName) {
            foreach ($result as $order) {
                $user = $order->$getterMethodName();

                // Check if the user exists and add to uniqueUsers if not already present
                if ($user !== null && !in_array($user, $uniqueUsers, true)) {
                    // Update user details if necessary
                    $this->updateUserDetails($user, $key_search, $total_employees);
                    $uniqueUsers[] = $user;
                }
            }
        }

        return $uniqueUsers; // Return the unique users based on the criteria
    }

    public function findModulesPerOrders(?string $keyword, ?array $key_search = [], ?string $user_type = null, ?int $limit = null, ?int $page = null)
    {
        // Get the current user companies
        $key_search = $this->getAuthCompanies($key_search);

        // Retrieve filtered orders based on provided parameters
        $result = $this->orderRepo->filteringData($keyword, $key_search, $limit, $page);

        // Initialize variables for storing unique types and total employee count
        $uniqueType = [];
        // Determine the getter method based on type ('vendor' or 'company')
        $getterMethodName = 'getModule';
        // Process each order to extract unique types based on  type
        if ($getterMethodName) {
            foreach ($result as $order) {
                $type = $order->$getterMethodName();
                // Check if the type exists and add to uniqueType if not already present
                if ($type !== null && !in_array($type, $uniqueType, true)) {
                    $uniqueType[] = $type;
                }
            }
        }

        return $uniqueType; // Return the unique types based on the criteria
    }

    private function updateUserDetails($user, array $key_search, int $total_employees): void
    {
        if (!empty($key_search['company'])) {
            // Get average rating for the company
            $orderRatings = $this->orderRatingRepository->findBy([
                'company' => $key_search['company'],
                'vendor' => $user
            ]);

            $averageRating = $this->calculateAverageRating($orderRatings);
            $user->company_rating = $averageRating;
            // Get employee rating count
            $rating_count = 0;
            foreach ($orderRatings  as $orderRating ) {
                $rating_count += $orderRating ? $orderRating->getOrderRatingDetails()->count() : 0;
            }
            $user->rating_count = $rating_count;
            // Set total employees count
            $user->total_employees = count($orderRatings) > 0 ? $total_employees * count($orderRatings) : $total_employees;
        }
    }


    public function find($order_id) : Order
    {
        $entityRepo =  $this->orderRepo->find($order_id);
        if (!$entityRepo) {
            throw new Error($this->translator('Order not found'));
        }
        return $entityRepo;
    }

    public function uploadAttachments($uploadedFiles, $order_id)
    {
        $order = $this->find($order_id);

        $postFixPath = 'order/'.$order_id.'/';
        foreach ($uploadedFiles['files'] as $uploadedFile) {
            if ($uploadedFile instanceof UploadedFile) {

                $response = $this->fileUploader->S3Upload($uploadedFile, $postFixPath);

                $entity = new OrderAttachment();
                $entity->setOrder($order);
                $entity->setFile($response['file_path']);
                $entity->setFileName($response['file_name']);

                $this->manager->persist($entity);
            }
        }

        $this->manager->flush();

        return true;
    }

    public function getOrderAttachments(int $order_id): array
    {
        $order = $this->find($order_id);
        $attachments = $order->getOrderAttachments();

        $attachmentFiles = [];
        foreach ($attachments as $attachment) {
            $attachmentFiles[] = $this->fileUploader->getAttachmentDetail($attachment);
        }

        return $attachmentFiles;
    }

    public function removeOrderAttachment(int $id): bool
    {
        $attachment = $this->manager->getRepository(OrderAttachment::class)->find($id);
        if (!$attachment) {
            throw new Error($this->translator('Order attachment not found.'));
        }

        return $this->fileUploader->removeFileFromServer($attachment);

    }

    public function createPerDayOrders(Order $order)
    {
        $company = $order->getCompany();
        $module = $this->moduleRepo->find(1); /*Always use lunch grace period so this function can be used for lunch, guest and meeting*/
        /*@todo
        make sub category for grace period to remove the static id check*/
        $grace_period = $this->graceRepo->findOneBy(['user'=>$company,'module'=>$module]);
        $working_days = $company->getWorkingDay();
        $minor_update = $grace_period->getMinorUpdate();

        $days_for_orders = ($minor_update) + 1; /*minor update plus one day*/
        $start_date = $order->getFromDate();
        $start_date = \DateTime::createFromFormat('Y-m-d', $start_date); // Convert to DateTime object

        $dayInterval = new \DateInterval('P1D'); // 1 day interval

        for ($day = 0; $day < ($days_for_orders); $day++) {
            // Create orders for each working day in the current week
            $currentDate = $start_date;
            $dayOfWeek = $start_date->format('N');
            $this->weekdaysChecks($currentDate,$company,$dayOfWeek,$working_days,$order);
            $start_date->add($dayInterval);
        }
    }

    public function createDailyOrder($date,Order $order)
    {
        $daily_order = $this->dailyOrderRepo->findOneBy(['order'=>$order,'order_date'=>$date]);
        if (!$daily_order)
        {
            $daily_order = new DailyOrder();
        }
        else
        {
            $dish_arr = [];
            foreach ($order->getOrderDetails() as $detail)
            {
                $dish_arr[] = $detail->getDish()->getId();
            }
            foreach ($daily_order->getDailyOrderDetails() as $existing_order_detail)
            {
                if (!in_array($existing_order_detail->getDish()->getId(),$dish_arr))
                {
                    $this->manager->remove($existing_order_detail);
                    $this->manager->flush();
                }
            }
        }

        $dayOfWeek = $date->format('N');
        $daily_order->setOrder($order);
        $daily_order->setStatus(0);
        $daily_order->setCompany($order->getCompany());
        $daily_order->setVendor($order->getVendor());
        $daily_order->setTotalHeads(0);
        $daily_order->setOrderDate($date);
        $daily_order->setUpdates(0);
        $this->manager->persist($daily_order);
        $this->manager->flush();
        $total_heads = 0;
        $this->manager->refresh($order);

        foreach ($order->getOrderDetails() as $orderDetail)
        {
            $heads = 0;
            if ($dayOfWeek == 1)
            {
                $heads = $orderDetail->getMondayHeads();
            }
            if ($dayOfWeek == 2)
            {
                $heads = $orderDetail->getTuesdayHeads();
            }
            if ($dayOfWeek == 3)
            {
                $heads = $orderDetail->getWednesdayHeads();
            }
            if ($dayOfWeek == 4)
            {
                $heads = $orderDetail->getThursdayHeads();
            }
            if ($dayOfWeek == 5)
            {
                $heads = $orderDetail->getFridayHeads();
            }
            if ($dayOfWeek == 6)
            {
                $heads = $orderDetail->getSaturdayHeads();
            }
            if ($dayOfWeek == 7)
            {
                $heads = $orderDetail->getSundayHeads();
            }
            $total_heads = $total_heads + $heads;
            $daily_order_detail = $this->dailyOrderDetailRepo->findOneBy([
                'dish'=> $orderDetail->getDish(),'daily_order'=>$daily_order
            ]);
            if (!$daily_order_detail)
            {
                $daily_order_detail = new DailyOrderDetail();
            }
            $daily_order_detail->setNoOfHeads($heads);
            $daily_order_detail->setDish($orderDetail->getDish());
            $daily_order_detail->setDailyOrder($daily_order);
            $this->manager->persist($daily_order_detail);
        }
        $daily_order->setTotalHeads($total_heads);
        $this->manager->persist($daily_order);
        $this->manager->flush();

    }

    /*
     * This function will return `true` if it's not a holiday
     * */
    public function checkHoliday(\DateTime $currentDate, User $user): bool
    {
        $publicHolidays = $user->getPublicHolidays();
        $currentDate = $currentDate->format('Y-m-d');
        foreach ($publicHolidays as $publicHoliday) {
            // Check if the current date falls between the from_date and to_date of the public holiday
            $fromDate = $publicHoliday->getFromDate();
            $toDate = $publicHoliday->getToDate();

            if ($currentDate == $fromDate)
            {
                return false;
            }

            if ($currentDate == $toDate)
            {
                return false;
            }

            if ($currentDate > $fromDate && $currentDate < $toDate) {
                return false;
            }
        }

        return true; // Current date is not a public holiday for the specific user
    }

    public function endDateCheck(Order $order,\DateTime $firstDay)
    {

        if ($order->getFromDate()) {
            $order_start_date = \DateTime::createFromFormat('Y-m-d', $order->getFromDate()); // Convert to DateTime object

            // Compare $firstDay with $order_end_date
            if ($firstDay->getTimestamp() < $order_start_date->getTimestamp()) {
                return false;
            }
        }


        if ($order->getToDate()) {
            $order_end_date = \DateTime::createFromFormat('Y-m-d', $order->getToDate()); // Convert to DateTime object

            // Compare $firstDay with $order_end_date
            if ($firstDay->getTimestamp() > $order_end_date->getTimestamp()) {
                return false;
            }
        }

        return true;
    }

    public function getDailyOrders($order_id, $week_no , $user = null, $currentYear = null)
    {
        $this->validateWeekNumber($week_no);
        // Create a DateTime object for the first day of the given week.
        $firstDay = new \DateTime();

        if (!$currentYear)
        {
            $currentYear = (int)date('Y');
        }

        $firstDay->setISODate($currentYear, $week_no, 1);
        $weekEndDay = clone $firstDay;
        $weekEndDay = $weekEndDay->modify('+6 day');

        $key_search = [
            'module' => 1,
            // 'date' => $firstDay->format('Y-m-d')
            'date_range' => [
                'from_date' => $firstDay->format('Y-m-d'),
                'to_date' => $weekEndDay->format('Y-m-d')
            ]
        ];
        if ($user)
        {
            $key_search['company'] = @$user->getParent() ?? $user;
        }

        $orders = $this->getOrder($order_id, $key_search);
        // $firstDay->modify('+6 day');
        // Create an array to store the dates for each day of the week.
        $weekDates = [];
        $i = 0; // Initialize the counter for inner loop
        foreach ($orders as $order)
        {
            $main_order_details = $order->getOrderDetails();
            $module_gp = $order->getModule()->getParent() ? $order->getModule()->getParent() : $order->getModule();
            $grace_period = $this->graceRepo->findOneBy(['user' => $order->getCompany(), 'module' => $module_gp]);

            while ($i < 7)
            {
                $orderFromDate = new DateTime($order->getFromDate());
                $orderToDate = new DateTime($order->getToDate());

                // Check date condition and skip to the next order if not met
                if (!($firstDay->format('Y-m-d') >= $orderFromDate->format('Y-m-d') && $firstDay->format('Y-m-d') <= $orderToDate->format('Y-m-d')))
                {
                    // Break the inner loop and move to the next order
                    if (count($orders) > 1)
                    {
                        break;
                    }
                }

                $company = $order->getCompany();
                $can_make_minor_update = $this->checkIfCanMakeMinorUpdate($firstDay->format('Y-m-d'), $grace_period);
                $can_make_major_update = $this->checkIfCanMakeMajorUpdate($firstDay->format('Y-m-d'), $grace_period);

                $check_day = $firstDay->format('l');
                $method = 'is' . $check_day;
                if (call_user_func([$company->getWorkingDay(), $method]) && $this->endDateCheck($order, $firstDay))
                {
                    $dailyOrder = $dailyOrderEntity = $this->dailyOrderRepo->findOneBy(['order' => $order, 'order_date' => $firstDay]);
                    if (!$dailyOrder)
                    {
                        $dailyOrder = [];
                        $dailyOrder['order_date'] = $firstDay->format('Y-m-d');
                        $dailyOrder['cancellation_date'] = null;
                        $dailyOrder['status'] = $can_make_minor_update == true ? null : 1;
                        $dailyOrder['can_major_update'] = $can_make_major_update;
                        $dailyOrder['user_cancel'] = $this->isDishCancel($dailyOrderEntity, $user, $firstDay);


                        $details_arr = [];
                        $details_arr_sub = [];
                        $total_heads = 0;
                        foreach ($main_order_details as $main_order_detail)
                        {
                            $details_arr_sub['dish'] = $main_order_detail->getDish();
                            $functionName = 'get' . ucfirst(strtolower($firstDay->format('l'))) . 'Heads';
                            // Check if the function exists before calling it.
                            if (method_exists($main_order_detail, $functionName))
                            {
                                $details_arr_sub['no_of_heads'] = call_user_func([$main_order_detail, $functionName]);
                                $total_heads += $details_arr_sub['no_of_heads'];
                            }
                            else
                            {
                                // Handle the case where the function doesn't exist.
                                $details_arr_sub['no_of_heads'] = 0; // or some default value;
                            }
                            if ($user)
                            {
                                $details_arr_sub['is_checked'] = $this->isCheckedDish($dailyOrderEntity, $main_order_detail, $user, $firstDay);
                            }

                            $details_arr[] = $details_arr_sub;
                        }
                        $dailyOrder['dailyOrderDetails'] = $details_arr;
                        $dailyOrder['total_heads'] = $total_heads;
                    }
                    else
                    {
                        $dailyOrder->setUserCancel($this->isDishCancel($dailyOrderEntity, $user, $firstDay));

                        // $orderPreference = $this->orderPreferencesRepository->findOneBy([
                        //     'daily_order' => $dailyOrderEntity,
                        //     'user' => $user,
                        //     'order_date' => $firstDay,
                        // ]);
                        // $dailyOrder->setUserCancel($orderPreference ? $orderPreference->isCancel() : false);
                    }

                    if ($user && $dailyOrder instanceof DailyOrder)
                    {
                        foreach ($dailyOrder->getDailyOrderDetails() as $getDailyOrderDetail)
                        {
                            $isChecked = $this->isCheckedDish($dailyOrderEntity, $getDailyOrderDetail, $user, $firstDay);
                            $getDailyOrderDetail->setIsChecked($isChecked);
                        }
                    }
                }
                else
                {
                    $dailyOrder = [];
                    $dailyOrder['order_date'] = $firstDay->format('Y-m-d');
                    $dailyOrder['cancellation_date'] = null;
                    $dailyOrder['status'] = null;
                    $dailyOrder['can_major_update'] = $can_make_major_update;
                    $dailyOrder['dailyOrderDetails'] = null;
                    $dailyOrder['total_heads'] = null;
                }

                $weekDates['week-'.$week_no]['orders'][] = $dailyOrder;
                $weekDates['week-'.$week_no]['week'] = 'week' . $week_no;
                $weekDates['week-'.$week_no]['module'] = $order->getModule();
                $firstDay->modify('+1 day');
                // it will contine where inner loop index ends
                $i++; // Increment the counter at the end of the loop
            }
        }
        return $weekDates;
    }

    public function getUserDailyOrders($order_id = null,$user, $week_no)
    {
        try {
            $user = $this->userRepo->find($user);
            if (!$user) {
                throw new Error($this->translator('User not found'));
            }
            return $this->getDailyOrders($order_id, $week_no , $user);
        } catch (\Throwable $th) {
            //throw $th;
            return null;
        }

    }

    public function getDailyOrdersPerWeek($year,$week_no,$key_search)
    {
        $from_to_dates = $this->getWeekDates($year,$week_no);
        $key_search['date_range'] = $from_to_dates;
        $key_search['no_of_visits_per_week'] = null;
        // Use array_filter() with a callback function for remove key thata contain 0 value
        $key_search = array_filter($key_search, function($value) {
            return $value !== "0";
        });
        // Get the current user companies
        $key_search = $this->getAuthCompanies($key_search);

        $orders = $this->orderRepo->filteringData(null,$key_search);

        $merged_array = [];
        foreach ($orders as $order)
        {
            $merged_array = $this->getWeeklyDailyOrders($order, $week_no, null, $merged_array);
        }
        $weekDates['week-'.$week_no]['orders'] = array_values($merged_array);
        $weekDates['week-'.$week_no]['week'] = 'week'.$week_no;
        return $weekDates;
    }

    public function getWeekDates($year, $week)
    {
        $dto = new \DateTime();

        // Set the year and week number
        $dto->setISODate($year, $week);

        // Get the start date (Monday)
        $startDate = $dto->format('Y-m-d');

        // Get the end date (Sunday)
        $endDate = $dto->modify('+6 days')->format('Y-m-d');

        return [
            'from_date' => $startDate,
            'to_date' => $endDate
        ];
    }

    public function getWeeklyDailyOrders($order, $week_no , $user = null, $merged_array)
    {
        $this->validateWeekNumber($week_no);

        $currentYear = (int)date('Y');
        // Create a DateTime object for the first day of the given week.
        $firstDay = new \DateTime();
        $firstDay->setISODate($currentYear, $week_no, 1);
        // $firstDay->modify('+6 day');
        // Create an array to store the dates for each day of the week.
        $weekDates = [];
        $main_order_details = $order->getOrderDetails();
        // Loop through each day of the week (Monday to Sunday) and calculate the date.
        for ($i = 0; $i < 7; $i++)
        {
            $company = $order->getCompany();

            $check_day = $firstDay->format('l');
            $method = 'is' . $check_day;
            if (call_user_func([$company->getWorkingDay(), $method]) && $this->endDateCheck($order,$firstDay))
            {
                $dailyOrder = $dailyOrderEntity =  $this->dailyOrderRepo->findOneBy(['order' => $order, 'order_date' => $firstDay]);

                if (!$dailyOrder)
                {
                    $dailyOrder = [];

                    $dailyOrder['order_date'] = $firstDay->format('Y-m-d');
                    $dailyOrder['cancellation_date'] = null;

                    $details_arr = [];
                    $details_arr_sub = [];
                    $total_heads = 0;
                    foreach ($main_order_details as $main_order_detail)
                    {
                        $details_arr_sub['dish'] = $main_order_detail->getDish();
                        $functionName = 'get' . ucfirst(strtolower($firstDay->format('l'))) . 'Heads';

                        // Check if the function exists before calling it.
                        if (method_exists($main_order_detail, $functionName)) {
                            $details_arr_sub['no_of_heads'] = call_user_func([$main_order_detail, $functionName]);
                            $total_heads = $total_heads + $details_arr_sub['no_of_heads'];
                        } else {
                            // Handle the case where the function doesn't exist.
                            $details_arr_sub['no_of_heads'] = 0; // or some default value
                        }
                        if ($user)
                        {
                            $details_arr_sub['is_checked'] = $this->isCheckedDish($dailyOrderEntity, $main_order_detail, $user, $firstDay);
                        }

                        $details_arr[] = $details_arr_sub;
                    }
                    $dailyOrder['dailyOrderDetails'] = $details_arr;
                    $dailyOrder['total_heads'] = $total_heads;
                }
                else
                {
                    // dd($dailyOrder);
                    $newDailyOrder = [];
                    $newDailyOrder['order_date'] = $firstDay->format('Y-m-d');
                    $newDailyOrder['cancellation_date'] = $dailyOrder->getCancellationDate();
                    $newDailyOrder['status'] = $dailyOrder->getStatus();
                    foreach ( $dailyOrder->getDailyOrderDetails() as $dailyOrderDetail)
                    {

                        $dish_array['dish']= $dailyOrderDetail->getDish();
                        if ($dailyOrder->getStatus() == 2)
                        {
                            $dish_array['no_of_heads']= 0;
                        }
                        else
                        {
                            $dish_array['no_of_heads']= $dailyOrderDetail->getNoOfHeads();

                        }

                        $newDailyOrder['dailyOrderDetails'][]= $dish_array;

                    }
                    if ($dailyOrder->getStatus() == 2) {
                        $newDailyOrder['total_heads'] =  0;
                    }
                    else
                    {
                        $newDailyOrder['total_heads'] =  $dailyOrder->getTotalHeads();
                    }

                    $dailyOrder = $newDailyOrder;
                }


                if ($user && $dailyOrder instanceof DailyOrder) {
                    foreach ($dailyOrder->getDailyOrderDetails() as $getDailyOrderDetail) {
                        $isChecked = $this->isCheckedDish($dailyOrderEntity, $getDailyOrderDetail, $user, $firstDay);
                        $getDailyOrderDetail->setIsChecked($isChecked);
                    }
                }

            }
            else
            {
                // valid for if week day off for some companies (contain old array)
                if (!empty($merged_array))
                {
                    if (!isset($merged_array[$firstDay->format('Y-m-d')]))
                    {
                        $dailyOrder = $this->createDailyOrderArray($firstDay->format('Y-m-d'));

                    }
                }
                else
                {
                    $dailyOrder = $this->createDailyOrderArray($firstDay->format('Y-m-d'));
                }


            }

            if (!empty($merged_array) && isset($merged_array[$firstDay->format('Y-m-d')]) && isset($dailyOrder))
            {
                if ($this->endDateCheck($order,$firstDay))
                {
                    $response = $this->handleEntityType($merged_array[$firstDay->format('Y-m-d')]);

                    $mergedDailyOrderDetails = $response['entity'];
                    $response = $this->handleEntityType($dailyOrder);
                    $dailyOrderDetails = $response['entity'];
                    $dailyOrderDate= $response['order_date'];

                    if (!empty($dailyOrderDetails) && !empty($mergedDailyOrderDetails))
                    {

                        $merged_response = $this->mergeDailyOrderDetails($dailyOrderDetails, $mergedDailyOrderDetails);
                        $merged_array[$firstDay->format('Y-m-d')]['dailyOrderDetails'] = $merged_response['merged_response'];
                        $merged_array[$firstDay->format('Y-m-d')]['total_heads'] = $merged_response['total_heads'];
                    }
                    else
                    {
                        if ($firstDay->format('Y-m-d') == $dailyOrderDate)
                        {
                            $merged_array[$firstDay->format('Y-m-d')] = $dailyOrder;
                        }
                    }
                }
            }
            else
            {
                if (isset($dailyOrder))
                {
                    $merged_array[$firstDay->format('Y-m-d')] = $dailyOrder;
                }
            }
            // $weekDates['week-'.$week_no]['orders'][] = array_values($merged_array);
            $firstDay->modify('+1 day');
        }

        return $merged_array;
    }

    private function handleEntityType($entityRecord)
    {
        if (is_object($entityRecord))
        {
            $entityRecordDetail = $entityRecord->getDailyOrderDetails();
            // dd($entityRecordDetail);
            $order_date = $entityRecord->getOrderDate();
        }

        if(is_array($entityRecord))
        {
            $entityRecordDetail = $entityRecord['dailyOrderDetails'];
            $order_date = $entityRecord['order_date'];
        }

        return [
            'entity' => $entityRecordDetail,
            'order_date' => $order_date,
        ];
    }
    private function mergeDailyOrderDetails($details1, $details2)
    {
        $merged_response = [];
        $total_heads = 0;

        // Combine both sets of details into a single array
        $allDetails = array_merge($details1, $details2);
        foreach ($allDetails as $item)
        {

            $total_heads += $item['no_of_heads'];

            $dishId = $item['dish']->getId();
            if (!isset($merged_response[$dishId])) {
                $merged_response[$dishId] = $item;
            } else {
                $merged_response[$dishId]['no_of_heads'] += $item['no_of_heads'];
            }
        }

        return [
            'merged_response' => array_values($merged_response),
            'total_heads' => $total_heads,
        ];
    }

    private function createDailyOrderArray($order_date)
    {
        return [
            'order_date' => $order_date,
            'cancellation_date' => null,
            'status' => null,
            'dailyOrderDetails' => null,
            'total_heads' => null
        ];
    }

    private function validateWeekNumber($week_no)
    {
        if ($week_no < 1 || $week_no > 52) {
            throw new Error($this->translator('Invalid Week Number'));
        }
    }

    private function getOrder($order_id,$key_search)
    {
        if ($order_id)
        {
            $orders = $this->orderRepo->findby(['id' => $order_id]);
        }
        else
        {
            $orders = $this->orderRepo->filteringData(null,$key_search);
            // if (count($orders) > 0)
            // {
            //     $order = $orders[0];
            // }
        }

        if (empty($orders))
        {
            throw new Error($this->translator('Order not found'));
        }
        return $orders;
    }

    private function isCheckedDish($dailyOrderEntity, $main_order_detail, $user, $firstDay)
    {
        $check_day = $firstDay->format('l');
        $getterDayMethod = 'get' . $check_day.'Dish';
        $orderPreference = $this->orderPreferencesRepository->findOneBy([
            'daily_order' => $dailyOrderEntity,
            'user' => $user,
            'order_date' => $firstDay,
        ]);

        if ($orderPreference)
        {
            return $orderPreference->getDish()->getId() == $main_order_detail->getDish()->getId();
        } else {
            $userPreference = $this->userDishPreferenceRepository->findOneBy([
                'user' => $user,
            ]);

            return $userPreference && $userPreference->$getterDayMethod() && $userPreference->$getterDayMethod()->getId() == $main_order_detail->getDish()->getId();
        }
    }

    private function isDishCancel($dailyOrderEntity, $user, $firstDay)
    {
        $check_day = $firstDay->format('l');
        $getterDayMethod = 'get' . $check_day.'Dish';
        $orderPreference = $this->orderPreferencesRepository->findOneBy([
            'daily_order' => $dailyOrderEntity,
            'user' => $user,
            'order_date' => $firstDay,
        ]);

        if ($orderPreference)
        {
            return $orderPreference->isCancel();
        } else {
            $userPreference = $this->userDishPreferenceRepository->findOneBy([
                'user' => $user,
            ]);

            return $userPreference && $userPreference->$getterDayMethod() && $userPreference->$getterDayMethod()->getId() == 0;
        }
    }


    public function checkIfMinorUpdate(String $order_date, array $dishes_arrs, GracePeriod $gracePeriod, Module $module) : array
    {
        $order_date = \DateTime::createFromFormat('Y-m-d', $order_date); // Convert to DateTime object
        $order_day = $order_date->format('l');
        $threshold = $gracePeriod->getThreshold();
        $company = $gracePeriod->getUser();
        $order_date->setTime(0,0,0,0);

        $active_order = $this->manager->createQueryBuilder()
            ->select('o')
            ->from(Order::class, 'o')
            ->where('o.module = :module')
            ->andWhere('o.company = :company')
            ->andWhere('(:order_date >= o.from_date AND (:order_date_to <= o.to_date OR o.to_date IS NULL))')
            ->setParameter('module', $module)
            ->setParameter('company', $company)
            ->setParameter('order_date', $order_date)
            ->setParameter('order_date_to', $order_date)
            ->getQuery()
            ->getOneOrNullResult();

        if (!$active_order)
        {
            throw new Error($this->translator('No active order in this date'));
        }

        if ($module->getParent()) /*for meeting and guest*/
        {
            $daily_order = $this->getOrSaveDailyOrderBasedOnDate($order_date->format('Y-m-d'),$company,$module);
            $total_original_heads = $daily_order->getTotalHeads();
        }
        else    /*for lunch and fruit*/
        {
            $original_order_details = $active_order->getOrderDetails();
            $total_original_heads = 0;
            foreach ($original_order_details as $order_detail)
            {
                $method = 'get' . $order_day . 'Heads';
                if (method_exists($order_detail, $method)) {
                    $heads = call_user_func([$order_detail, $method]);
                    $total_original_heads = $total_original_heads + $heads;
                }
            }
        }
        $min_heads = $total_original_heads - $threshold;
        $max_heads = $total_original_heads + $threshold;
        $total_heads_to_be = 0;
        foreach ($dishes_arrs as $dishes_arr)
        {
            $total_heads_to_be = $total_heads_to_be + $dishes_arr['heads'];
        }
        if ($total_heads_to_be < $min_heads || $total_heads_to_be > $max_heads)
        {
            return ['status'=>false, 'changes'=> ($total_original_heads - $total_heads_to_be)];
        }
        return ['status'=>true, 'changes'=> ($total_original_heads - $total_heads_to_be)];
    }

    public function checkIfCanMakeMinorUpdate(String $order_date, GracePeriod $gracePeriod) : bool
    {
        $currentDate = new \DateTime();

        $order_date = \DateTime::createFromFormat('Y-m-d', $order_date); // Convert to DateTime object

        $minor_update = $gracePeriod->getMinorUpdate();

        $company = $gracePeriod->getUser();
        $working_days = $company->getWorkingDay();

        $time_gp = $gracePeriod->getMinorUpdateTime()?:'00:00:00';
        $current_time = new \DateTime();
        $gp_time = new \DateTime();
        $h = explode(':', $time_gp)[0];      /*hours*/
        $i = explode(':', $time_gp)[1];      /*mins*/
        $gp_time->setTime($h,$i,0,0);

        if($gp_time->getTimestamp() > $current_time->getTimestamp()) /*Unlock last day if time has not passed yet*/
        {
            $minor_update--;
        }

        $i = 0;
        while ($i <= $minor_update)
        {
            $currentDate->modify('+1 day');
            $check_day = $currentDate->format('l');
            $method = 'is' . $check_day;
            if ($this->checkHoliday($currentDate, $company) && call_user_func([$working_days, $method]))
            {
                $i++;
            }
        }
        $currentDate->setTime(0,0,0,0);$order_date->setTime(0,0,0,0);
        $interval = $currentDate->diff($order_date);

        // Check if the order date is ahead of or the same as the current date
        if ($interval->invert === 0) {
            // Date 1 is ahead of Date 2 or they are the same
            return true;
        } else {
            // Date 1 is before Date 2
            return false;
        }
    }

    public function checkHolidayForVendor($currentDate)
    {
        $publicHolidays = $this->publicHolidayRepo->findBy(['default' => true]);
        $currentDate = $currentDate->format('Y-m-d');
        foreach ($publicHolidays as $publicHoliday) {
            // Check if the current date falls between the from_date and to_date of the public holiday
            $fromDate = $publicHoliday->getFromDate();
            $toDate = $publicHoliday->getToDate();

            if ($currentDate == $fromDate)
            {
                return false;
            }

            if ($currentDate == $toDate)
            {
                return false;
            }

            if ($currentDate > $fromDate && $currentDate < $toDate) {
                return false;
            }
        }

        return true; // Current date is not a public holiday for the vendor
    }

    public function getComingWorkingDayForVendor(\DateTime $currentDate)
    {
        $check_day = $currentDate->format('l');
        $working_days = $this->workingDayService->getDefaultWorkingDays();
        $method = 'is' . $check_day;
        if (!$this->checkHolidayForVendor($currentDate) || !call_user_func([$working_days, $method]))
        {
            $previousDate = clone $currentDate;
            $previousDate->modify('+1 day');
            return $this->getComingWorkingDayForVendor($previousDate);
        }
        return $currentDate;
    }

    public function getComingWorkingDay(\DateTime $currentDate, User $company)
    {
        $check_day = $currentDate->format('l');
        $working_days = $company->getWorkingDay();
        $method = 'is' . $check_day;
        if (!$this->checkHoliday($currentDate, $company) || !call_user_func([$working_days, $method]))
        {
            $previousDate = clone $currentDate;
            $previousDate->modify('+1 day');
            return $this->getComingWorkingDay($previousDate, $company);
        }
        return $currentDate;
    }

    public function checkIfCanMakeMajorUpdate(String $order_date, GracePeriod $gracePeriod, $method = 'getMajorUpdate')
    {
        $currentDate = new \DateTime();
        $order_date = \DateTime::createFromFormat('Y-m-d', $order_date); // Convert to DateTime object
        $time_gp = $gracePeriod->{$method.'Time'}();
        $checkDate = clone $currentDate;
        $h = explode(':', $time_gp)[0];      /*hours*/
        $i = explode(':', $time_gp)[1];      /*mins*/
        $checkDate->setTime($h, $i);
        if ($currentDate->getTimestamp() > $checkDate->getTimestamp())
        {
            $currentDate->modify('+1 day');
        }
        $currentDate->setTime(0,0,0,0);
        $order_date->setTime(0,0,0,0);
        $major_update = $gracePeriod->{$method}();
        $current_week = $currentDate->format('W');
        $order_week = $order_date->format('W');
        $company = $gracePeriod->getUser();
        $working_days = $company->getWorkingDay();

        if ($current_week == $order_week)
        {
            return false;
        }

        $i = 0;
        while ($current_week < $order_week)
        {
            $currentDate->modify('+1 day');
            $check_day = $currentDate->format('l');
            $method = 'is' . $check_day;
            if ($this->checkHoliday($currentDate, $company) && call_user_func([$working_days, $method]))
            {
                $i++;
                $current_week = $currentDate->format('W');
            }
        }

        if (($i - $major_update) <= 0)
        {
            return false;
        }
        return true;
    }

    public function orderValidations(String $order_date,array $dishes_arrs,GracePeriod $grace_period, Module $module)
    {
        $token = $this->tokenStorageInterface->getToken();
        if($token->getUser()->hasRole('ROLE_ADMIN'))
        {
            return true;
        }
        $update = $this->checkIfMinorUpdate($order_date,$dishes_arrs, $grace_period, $module);
        if($update['status'])
        {
            $can_make_minor_update = $this->checkIfCanMakeMinorUpdate($order_date, $grace_period);
            if (!$can_make_minor_update)
            {
                throw new Error($this->translator('minor_grace_period_expired',['%days%'=>$grace_period->getMinorUpdate()]));
            }
        }
        else    /*major update*/
        {
            $can_make_major_update = $this->checkIfCanMakeMajorUpdate($order_date, $grace_period);
            if (!$can_make_major_update)
            {
                $company = $grace_period->getUser();
                $contact_persons = $this->userRepo->findBy(['parent' => $company->getId(), 'role' => 2],['id' => 'ASC']);
                if (!empty($contact_persons)) {
                    $contact_person = $contact_persons[0]; // Use the first result
                    NotificationService::sendThresholdLimitReachedEmailToWoplaAdmin($company,$contact_person,$update['changes'], $this->ti, $this->manager, $this->mailer);
                }
                $time_gp = $grace_period->getMajorUpdateTime();
                $h = explode(':', $time_gp)[0];      /*hours*/
                $i = explode(':', $time_gp)[1];      /*mins*/
                throw new Error($this->translator('major_grace_period_expired', [
                    '%major_update%' => $grace_period->getMajorUpdate(),
                    '%hour%' => $h,
                    '%minute%' => $i,
                ]));
            }
        }
    }

    public function getOrSaveDailyOrderBasedOnDate(String $order_date,User $company,Module $module, $return_only_main_order = false,$save = true) : DailyOrder|Order|null
    {

        $active_order = $this->manager->createQueryBuilder()
            ->select('o')
            ->from(Order::class, 'o')
            ->where('o.module = :module')
            ->andWhere('o.company = :company')
            ->andWhere('(:order_date >= o.from_date AND (:order_date <= o.to_date OR o.to_date IS NULL))')
            ->setParameter('module', $module)
            ->setParameter('company', $company)
            ->setParameter('order_date', $order_date)
            ->getQuery()
            ->getOneOrNullResult();

        if($return_only_main_order)
        {
            return $active_order;
        }

        if (!$active_order)
        {
            throw new Error($this->translator('No active order in this date'));
        }

        $order_date = \DateTime::createFromFormat('Y-m-d', $order_date); // Convert to DateTime object
        // in future use getOrSaveDailyOrder
        $dailyOrder = $this->dailyOrderRepo->findOneBy(['company'=>$company,'order'=>$active_order,'order_date'=>$order_date]);
        if (!$dailyOrder)
        {
            $dailyOrder = new DailyOrder();
            $dailyOrder->setOrderDate($order_date);
            $dailyOrder->setOrder($active_order);
            $dailyOrder->setVendor($active_order->getVendor());
            $dailyOrder->setCompany($company);
            $dailyOrder->setStatus(0);
            $dailyOrder->setTotalHeads(0);
            $dailyOrder->setUpdates(0);
            $dailyOrder->setCancellationDate(null);
            if ($save)
            {
                $this->manager->persist($dailyOrder);
                $this->manager->flush();
            }


            $active_order_details = $active_order->getOrderDetails();
            $total_heads = 0;
            foreach ($active_order_details as $main_order_detail)
            {
                $dailyOrderDetail = new DailyOrderDetail();
                $dailyOrderDetail->setDailyOrder($dailyOrder);
                $dailyOrderDetail->setDish($main_order_detail->getDish());
                $functionName = 'get' . ucfirst(strtolower($order_date->format('l'))) . 'Heads';

                // Check if the function exists before calling it.
                if (method_exists($main_order_detail, $functionName)) {
                    $heads = call_user_func([$main_order_detail, $functionName]);
                    $dailyOrderDetail->setNoOfHeads($heads);
                    $total_heads = $total_heads + $heads;
                }
                if ($save)
                {
                    $this->manager->persist($dailyOrderDetail);
                    $this->manager->flush();
                }
            }
            $dailyOrder->setTotalHeads($total_heads);
            if ($save)
            {
                $this->manager->persist($dailyOrder);
                $this->manager->flush();
            }
        }
        if ($save)
        {
            $this->manager->refresh($dailyOrder);
        }
        return $dailyOrder;

    }

    public function saveDailyOrder($daily_order)
    {
        $dishes_arrs = $daily_order['dishes_arr'];
        $company = $this->userRepo->find($daily_order['company_id']);
        $module = $this->moduleRepo->find($daily_order['module_id']);
        $gp_module = $module->getParent() ? $module->getParent() : $module;
        $grace_period = $this->graceRepo->findOneBy(['user'=>$company,'module'=>$gp_module]);
        $user = null;
        if (!empty($daily_order['user'])) {
            $user = $this->userRepo->find($daily_order['user']);
        }
        else
        {
            $this->orderValidations($daily_order['order_date'], $dishes_arrs, $grace_period, $module);

        }

        return $this->updateDailyDishHeads($daily_order, $dishes_arrs, $user);
    }

    public function saveDailyOrders($args)
    {

        $company = $this->userRepo->find($args['company_id']);
        $module = $this->moduleRepo->find($args['module_id']);
        $gp_module = $module->getParent() ? $module->getParent() : $module;
        $grace_period = $this->graceRepo->findOneBy(['user'=>$company,'module'=> $gp_module]);
        $user = null;
        if (!empty($args['user'])) {
            $user = $this->userRepo->find($args['user']);
        }
        $daily_orders = [];
        foreach ($args['date_dishes_arr'] as $date_dishes_arr)
        {
            $dishes_arrs = $date_dishes_arr['dishes'];

            $args['order_date'] = $date_dishes_arr['date'];

            if (!$user)
            {
                $this->orderValidations($date_dishes_arr['date'], $dishes_arrs, $grace_period, $module);
            }

            $daily_orders[] = $this->updateDailyDishHeads($args, $dishes_arrs, $user);
        }

        return $daily_orders;
    }

    public function updateDailyDishHeads($args, $dishes_arrs, $user)
    {
        $order_date = $args['order_date'];
        $converted_order_date = new \DateTime($order_date);
        $check_day = $converted_order_date->format('l');

        $module = $this->moduleRepo->find($args['module_id']);
        $company = $this->userRepo->find($args['company_id']);

        $dailyOrder = $this->getOrSaveDailyOrderBasedOnDate($order_date, $company, $module);
        $total = 0;

        foreach ($dishes_arrs as $dish_arr)
        {
            $heads = $dish_arr['heads']??0;
            $daily_order_detail = null;
            if ($dish_arr['dish_id'] != 0)
            {
                $daily_order_detail = $this->findDailyOrderDetailByOrderIdAndDishId($dailyOrder, $dish_arr['dish_id']);
            }

            if (!isset($dish_arr['heads']))
            {

                if (!empty($dish_arr['is_checked']) && $dish_arr['is_checked'] == true)
                {
                    $checkedDish = $this->dishRepo->find($dish_arr['dish_id']);
                    // cancel lunch
                    if ($dish_arr['dish_id'] == 0)
                    {
                        foreach ($dailyOrder->getDailyOrderDetails() as $getDailyOrderDetail)
                        {
                            // set default dish
                            $checkedDish = $getDailyOrderDetail->getDish();
                            if ($this->isCheckedDish($dailyOrder, $getDailyOrderDetail, $user, $converted_order_date))
                            {
                                $checkedDish = $getDailyOrderDetail->getDish();
                                // $this->updateDishHeads($dailyOrder->getDailyOrderDetails(),$checkedDish->getId(),false);
                                break; // Exit the loop if isCheckedDish returns true
                            }
                        }
                    }

                    $orderPreference = $this->orderPreferencesRepository->findOneBy([
                        'daily_order' => $dailyOrder,
                        'user' => $user,
                        'order_date' => $converted_order_date,
                    ]);

                    $daily_order_detail = $this->findDailyOrderDetailByOrderIdAndDishId($dailyOrder, $checkedDish->getId());
                    if (!$orderPreference)
                    {
                        $orderPreference = new OrderPreferences;
                        $daily_order_detail->setNoOfHeads($daily_order_detail->getNoOfHeads());
                        $this->manager->persist($daily_order_detail);
                    }
                    else
                    {
                        if ($dish_arr['dish_id'] != 0 &&
                            ($orderPreference->isCancel() == false || $orderPreference->isCancel() == null))
                        {
                            $oldDishId = $orderPreference->getDish()->getId();
                            if ($oldDishId != (int)$dish_arr['dish_id'])
                            {
                                $this->updateDishHeads($dailyOrder->getDailyOrderDetails(),$dish_arr['dish_id'],true);
                                $this->updateDishHeads($dailyOrder->getDailyOrderDetails(),$oldDishId,false);
                            }
                        }
                    }

                    if ($dish_arr['dish_id'] == 0 &&
                        ($orderPreference->isCancel() == false || $orderPreference->isCancel() == null)
                    )
                    {
                        $this->updateDishHeads($dailyOrder->getDailyOrderDetails(),$checkedDish->getId(),false);
                        $orderPreference->setCancel(true);
                    }
                    else
                    {
                        if ($dish_arr['dish_id'] != 0 && $orderPreference->isCancel())
                        {
                            $this->updateDishHeads($dailyOrder->getDailyOrderDetails(),$dish_arr['dish_id'],true);
                            $orderPreference->setCancel(false);
                        }
                    }

                    $orderPreference->setDish($checkedDish);
                    $orderPreference->setOrderDate($converted_order_date);
                    $orderPreference->setDailyOrder($dailyOrder);
                    $orderPreference->setUser($user);
                    $this->manager->persist($orderPreference);
                    $this->manager->flush();
                }
            }
            else
            {
                $daily_order_detail->setNoOfHeads($heads);
                $this->manager->persist($daily_order_detail);
            }
        }
        $total = 0;
        foreach ($dailyOrder->getDailyOrderDetails() as $getDailyOrderDetail)
        {
            $total = $total + $getDailyOrderDetail->getNoOfHeads();

        }

        $dailyOrder->setTotalHeads($total);
        $this->manager->persist($dailyOrder);
        $this->manager->flush();

        $this->manager->refresh($dailyOrder);

        return $dailyOrder;
    }

    // Function to update dish heads in the order details
    public function updateDishHeads($orderDetail, int $dishId, bool $isAdd)
    {
        if (!$orderDetail) {
            return null;
        }
        $filteredDetails = $orderDetail->filter(function($detail) use ($dishId) {
            return $detail->getDish()->getId() == $dishId;
        });
        $matchingDetail = $filteredDetails->first();
        // dd($matchingDetail->getNoOfHeads());
        if ($matchingDetail)
        {
            // if ($matchingDetail->getNoOfHeads())
            // {
            $heads = $matchingDetail->getNoOfHeads() + ($isAdd ? 1 : -1);
            if ($heads >= 0)
            {
                $matchingDetail->setNoOfheads($heads);
                $this->manager->persist($matchingDetail);
                $this->manager->flush();
            }


            // }

        }
    }

    public function findDailyOrderDetailByOrderIdAndDishId($dailyOrder, int $dishId)
    {

        $daily_order_detail =  $this->dailyOrderDetailRepo->findOneBy([
            'daily_order' => $dailyOrder,
            'dish' => $dishId,
        ]);
        if(!$daily_order_detail)
        {
            $daily_order_detail = new DailyOrderDetail();
            $daily_order_detail->setNoOfHeads(0);
            $daily_order_detail->setDailyOrder($dailyOrder);
            $daily_order_detail->setDish($this->dishRepo->find($dishId));
            $this->manager->persist($daily_order_detail);
            $this->manager->flush();
        }
        return $daily_order_detail;
    }

    public function cancelDailyOrder($order_date, $company_id, $module_id)
    {

        // Find the Daily Order entity by its ID
        $module = $this->moduleRepo->find($module_id);
        $module_gp = $module->getParent() ? $module->getParent() : $module;


        $company = $this->userRepo->find($company_id);
        $grace_period = $this->graceRepo->findOneBy(['user'=>$company,'module'=>$module_gp]);

        $entityRepo = $this->getOrSaveDailyOrderBasedOnDate($order_date, $company, $module);
        // Check if the entity was not found
        if (!$entityRepo) {
            throw new Error($this->translator('daily_order_not_found'));
        }
        $currentStatus = $entityRepo->getStatus();

        $token = $this->tokenStorageInterface->getToken();
        if (!$token->getUser()->hasRole('ROLE_ADMIN'))
        {
            if (!$this->checkIfCanMakeMajorUpdate($order_date,$grace_period,$method='getCancellation'))
            {
                $msgKey = $currentStatus == 0 ? 'cannot_cancel_order' : 'cannot_undo_cancel_order';
                throw new Error($this->translator($msgKey));
            }
        }

        $currentDate = new \DateTime();

        if ($currentStatus === 0 || $currentStatus === 1) {
            // If the order is currently active (status 0), cancel it (set status to 2)
            $entityRepo->setStatus(2);
//                $currentDate = \DateTime::createFromFormat('Y-m-d', $currentDate); // Convert to DateTime object
            $entityRepo->setCancellationDate($currentDate);
            $entityRepo->setCancelledBy($token->getUser());
            $vendor = $entityRepo->getVendor();
            if ($vendor && count($vendor->getPrimaryContactPersons()) > 0)
            {
                foreach ($vendor->getPrimaryContactPersons() as $primaryUser)
                {
                    if (filter_var($primaryUser->getEmail(), FILTER_VALIDATE_EMAIL))
                    {
                        NotificationService::cancelDailyOrderEmailToVendor($this->ti, $primaryUser, $company, $entityRepo, $this->mailer);
                    }
                }

            }
        } elseif ($currentStatus === 2) {
            // If the order is currently cancelled (status 1), uncancel it (set status to 0)
            $entityRepo->setStatus(0);
            $entityRepo->setCancellationDate(null);
            $entityRepo->setCancelledBy(null);
        }

        $this->manager->persist($entityRepo);
        $this->manager->flush();

        return $entityRepo;


    }

    // in progress
    public function getAllDailyOrders(?string $keyword, ?array $key_search = [], ?int $limit = null, ?int $page = null)
    {

        $qb = $this->dailyOrderRepo->createQueryBuilder('p');

        if (!empty($key_search)) {
            foreach ($key_search as $key => $value) {
                // Ensure that the parameter is named uniquely to avoid conflicts
                $paramName = 'param_' . $key;

                $qb->andWhere('p.'.$key.' = :'.$paramName)
                    ->setParameter($paramName, $value);
            }
        }

        if ($limit !== null) {
            $qb->setMaxResults($limit);
        }

        if ($page !== null) {
            // Calculate the offset based on the page and limit
            $offset = ($page - 1) * $limit;
            $qb->setFirstResult($offset);
        }

        return $qb->getQuery()->getResult();
    }

    public function setTrial($data)
    {

        if (isset($data['is_trial']) && $data['is_trial'] == true)
        {
            if (empty($data['from_date']))
            {
                throw new Error($this->translator('Start date is mandatory'));
            }

            if (empty($data['to_date']))
            {
                throw new Error($this->translator('End date is mandatory'));
            }
        }
        else
        {
            return false;
        }

        $filter_data['company'] = $data['company_id'];
        $filter_data['vendor'] = $data['vendor_id'];
        $filter_data['module'] = $data['module_id'];
        $filter_data['no_of_visits_per_week'] = null; // component base false

        if(!empty($data['id']))
        {
            $filter_data['exclude_params']['id'] = $data['id'];

        }

        $filter_data['date'] = new \DateTime($data['from_date']);
        $orders = $this->orderRepo->filteringData(null,$filter_data);
        // dd($orders);
        if (count($orders) > 0)
        {
            throw new Error($this->translator('order_exists_in_date_range', [
                '%date%' => $data['from_date'],
            ]));
        }
        $filter_data['date'] = new \DateTime($data['to_date']);
        $orders = $this->orderRepo->filteringData(null,$filter_data);
        if (count($orders) > 0)
        {

            throw new Error($this->translator('order_exists_in_date_range', [
                '%date%' => $data['to_date'],
            ]));
        }

        return true;
    }

    public function getActiveOrder(?string $keyword, ?array $key_search = [], ?int $limit = null, ?int $page = null)
    {
        if(isset($key_search['date_range']) && isset($key_search['date_range']['from_date']) && isset($key_search['date_range']['to_date']))
        {

            $startDate = $this->getDateTime($key_search['date_range']['from_date']);
            $endDate = $this->getDateTime($key_search['date_range']['to_date']);

            $filterData = [
                'company' => $key_search['company'],
                'module' => 1
            ];

            for ($date = clone $startDate; $date <= $endDate; $date->modify('+1 day')) {
                $filterData['date'] = $date;
                $orders = $this->orderRepo->filteringData(null,$filterData);
                if (count($orders) === 0) {
                    return null;
                }
            }
        }

        $orders = $this->orderRepo->filteringData(null,$key_search);
        // dd($orders);
        // if (count($orders) > 1)
        // {
        //     return null;
        // }
        if (empty($orders))
        {
            return null;
        }

        return $orders[0];
    }

    public function getOrders(?string $keyword, ?array $key_search = [], ?int $limit = null, ?int $page = null)
    {
        $key_search = array_filter($key_search, function($value) {
            return $value !== "0";
        });

        $orders = $this->orderRepo->filteringData(null,$key_search);
        if (!empty($key_search['date_range']) && $key_search['date_range']['from_date'] && $key_search['date_range']['to_date'])
        {
            foreach ($orders as $order)
            {
                $company = $order->getCompany();
                $totalHeads = 0;
                $dateRange = $this->getDateRange($key_search['date_range']['from_date'],$key_search['date_range']['to_date']);

                foreach ($dateRange as $date)
                {
                    // Setting the time to the end of the day (23:59:59)
                    // $date->modify('23:59:59');

                    $check_day = $date->format('l');
                    $method = 'is' . $check_day;
                    if (call_user_func([$company->getWorkingDay(), $method]) && $this->endDateCheck($order,$date))
                    {
                        $dailyOrder  =  $this->dailyOrderRepo->findOneBy(['order' => $order, 'order_date' => $date]);

                        $getMethod = 'get' . $check_day.'Heads';
                        // if dailyorder not created fetch data from order details
                        if (!$dailyOrder)
                        {
                            $total_heads = 0;
                            foreach ($order->getOrderDetails() as $getOrderDetail)
                            {
                                $total_heads += $getOrderDetail->$getMethod()?:0;
                            }
                            $totalHeads += $total_heads;
                        }
                        else
                        {
                            $totalHeads += $dailyOrder->getTotalHeads()?:0;
                        }
                    }
                }
                $order->setNoOfHeads($totalHeads);
            }

        }
        return $orders;
    }


    public function getActiveOrderDishes(?string $keyword, ?array $key_search = [], ?String $data_type, ?int $limit = null, ?int $page = null)
    {
        $date = (new \DateTime())->format('Y-m-d');
        $key_search['date'] = $date;

        $orders = $this->orderRepo->filteringData(null,$key_search);
        if (empty($orders))
        {
            if ($data_type == 'future')
            {
                unset($key_search['date']);
                $key_search['date_range']['from_date'] = $date;
                $orders = $this->orderRepo->filteringData(null,$key_search);
            }

            if (empty($orders))
            {
                return [];
            }
        }
        $order = $orders[0];
        $dishes = [];

        foreach ($order->getOrderDetails() as $orderDetail) {
            $dishes[] = $orderDetail->getDish();
        }

        return $dishes;
    }

    public function getDailyOrdersPreferences(?array $key_search = [])
    {
        // Use array_filter() with a callback function for remove key thata contain 0 value
        $key_search = array_filter($key_search, function($value) {
            return $value !== "0";
        });
        $dishId = null;
        $orderPreferenceSearchArr =[];
        if (!empty($key_search['dish']))
        {
            $dishId = $key_search['dish'];
            $orderPreferenceSearchArr['dish'] = $dishId;
            unset($key_search['dish']);
        }
        // fetch orders
        $orders = $this->orderRepo->filteringData(null,$key_search);
        $order_date = (new \DateTime($key_search['date']));
        $user_preferences = [];

        foreach ($orders as $order)
        {

            $check_day = $order_date->format('l');
            $getterDayMethod = 'get' . $check_day.'Dish';
            foreach ($order->getCompany()->getChildren() as $user)
            {

                $basicPreferenceSearchArr['user'] = $orderPreferenceSearchArr['user'] = $user;
                $basicPreferenceSearchArr['order_date'] = $orderPreferenceSearchArr['order_date'] = $order_date;

                $orderPreference = $this->orderPreferencesRepository->findOneBy($orderPreferenceSearchArr);

                if ($orderPreference && !$orderPreference->isCancel())
                {
                    $user_preferences[$user->getId()] = [
                        'user' => $orderPreference->getUser(),
                        'dish' => $orderPreference->getDish(),
                        'company' => $order->getCompany(),
                        'vendor' => $order->getVendor(),
                    ];
                }
                else
                {
                    $basicorderPreference = $this->orderPreferencesRepository->findOneBy($basicPreferenceSearchArr);
                    if (!$basicorderPreference)
                    {
                        $userPreference = $this->userDishPreferenceRepository->findOneBy([
                            'user' => $user,
                        ]);

                        if ($userPreference && $userPreference->$getterDayMethod())
                        {
                            if (!$dishId || $dishId == $userPreference->$getterDayMethod()->getId())
                            {
                                $user_preferences[$user->getId()] = [
                                    'user' => $userPreference->getUser(),
                                    'dish' => $userPreference->$getterDayMethod(),
                                    'company' => $order->getCompany(),
                                    'vendor' => $order->getVendor()
                                ];
                            }


                        }
                    }
                }

            }
        }
        return array_values($user_preferences);
    }

    public function getOrdersPreferences(?string $keyword, ?array $key_search = [], ?int $limit = null, ?int $page = null)
    {
        return $this->orderPreferencesRepository->filteringData(null,$key_search);
    }

    public function getAuthCompanies($key_search){
        // Get the current user's token
        $token = $this->tokenStorageInterface->getToken();
        // Check if the user has the ROLE_CLIENT_ADMIN role
        $loggedUser = $token->getUser();
        $loggedParentUser = $loggedUser->getParent();

        // Check if the user has the required roles and relationships
        if ($loggedParentUser && $loggedUser->hasRole('ROLE_CLIENT_ADMIN') && $loggedParentUser->hasRole('ROLE_COMPANY')) {
            // If 'company' key is not set in $key_search, retrieve and set companies for CLIENT_ADMIN users
            if (!isset($key_search['company'])) {
                $companies = [$loggedParentUser->getId()];

                // Retrieve companies associated with the current CLIENT_ADMIN user
                foreach ($loggedUser->getClientAdmins() as $item) {
                    $companies[] = $item->getCompany()->getId();
                }

                // Set 'company' in 'include_params' key for filtering
                $key_search['include_params'] = [
                    'company' => array_unique($companies)
                ];
            }
        }
        return $key_search;
    }

    public function sendOrderListEmail($args)
    {
        $key_search = $args['key_search'];

        $key_search = $this->getAuthCompanies($key_search);

        $order_date = \DateTime::createFromFormat('Y-m-d', $key_search['date']);

        $this_week_no = $order_date->format('W');
        $next_week_no = (int)$this_week_no + 1;

        $getDayHeads = 'get' . ucfirst(strtolower($order_date->format('l'))) . 'Heads';
        // dd($getDayHeads);
        $key_search['date'] = $order_date;

        $orders = $this->orderRepo->filteringData(null,$key_search);

        if (!empty($orders))
        {
            $guest_this_week_array = $meeting_this_week_array = $bakery_this_week_array = [];

            $order = $orders[0];

            $dailyOrder = $this->getOrSaveDailyOrder($order, $order_date, false);

            $company = $order->getCompany();

            $check_day = $order_date->format('l');

            $method = 'is' . $check_day;
            if (call_user_func([$company->getWorkingDay(), $method]) && $this->checkHoliday($order_date,$company))             /*check for holiday and working day*/
            {
                if ( $dailyOrder->getStatus() == 2)
                {
                    throw new Error($this->translator('Order is cancelled on selected date'));
                }

                $lunch_this_week_array = $this->getWeekArray($this_week_no, $dailyOrder->getOrder(), $order_date);
                $next_week_array = $this->getWeekArray($next_week_no, $dailyOrder->getOrder(), $order_date);

                $totalHeads = $dailyOrder->getTotalHeads();

                $key_search['module'] = 4; // guest
                $guestOrders = $this->orderRepo->filteringData(null,$key_search);

                $guest_daily_order = null;

                if (!empty($guestOrders)) {
                    $guest_order = $guestOrders[0];

                    $guest_daily_order = $this->getOrSaveDailyOrder($guest_order, $order_date, false);
                    $totalHeads = $totalHeads + $guest_daily_order->getTotalHeads();

                    $guest_this_week_array = $this->getWeekArray($this_week_no, $guest_daily_order->getOrder(), $order_date);

                }
                // meeting order
                $meeting_module = $this->moduleRepo->findOneBy(['name'=>'meeting']);
                $key_search['module'] = $meeting_module;

                $meetingOrders = $this->orderRepo->filteringData(null,$key_search);
                $meeting_daily_order = null;
                if (!empty($meetingOrders))
                {
                    $meeting_order = $meetingOrders[0];
                    $meeting_daily_order = $this->getOrSaveDailyOrder($meeting_order, $order_date, false);

                    $meeting_this_week_array = $this->getWeekArray($this_week_no, $meeting_daily_order->getOrder(), $order_date);

                }
                // bakery order
                $bakery_module = $this->moduleRepo->findOneBy(['id'=> 5]);
                $key_search['module'] = $bakery_module;
                $bakeryOrders = $this->orderRepo->filteringData(null,$key_search);

                $bakery_daily_order = null;
                if (!empty($bakeryOrders))
                {
                    $bakery_order = $bakeryOrders[0];
                    $bakery_daily_order = $this->getOrSaveDailyOrder($bakery_order, $order_date, false);
                    $bakery_this_week_array = $this->getWeekArray($this_week_no, $bakery_daily_order->getOrder(), $order_date);
                }
                $order_array[] = [
                    'dailyOrder' => $dailyOrder,
                    'mainOrder' => $order,
                    'totalHeads' => $totalHeads,
                    'guestOrder' => $guest_daily_order,
                    'meetingOrder' => $meeting_daily_order,
                    'bakeryOrder' => $bakery_daily_order,
                    'getDayHeads' => $getDayHeads,
                ];

                $this_week_array = $this->combineModuleWeekOrders($lunch_this_week_array, $guest_this_week_array, $meeting_this_week_array, $bakery_this_week_array);

                foreach ($args['email'] as $email)
                {
                    if (filter_var($email, FILTER_VALIDATE_EMAIL))
                    {
                        $locale = 'da';
                        // $locale = 'en';
                        $email = (new TemplatedEmail())
                            ->sender($_ENV['SENDER_EMAIL'])
                            ->to($email)
                            ->subject('Daily Order')
                            ->htmlTemplate('emails/daily_email.html.twig')
                            ->context([
                                'company' => $order->getCompany(),
                                'address' => @$order->getCompany()->getAddressCompany(),
                                'this_week_array' => $this_week_array,
                                'next_week_array' => $next_week_array,
                                'order_array' => $order_array,
                                'locale' => $locale,
                                'chat_url' => $_ENV['FRONTEND_URL'].'/chat'
                            ]);
                        $this->mailer->send($email);
                    }
                }
            }
            else
            {
                throw new Error($this->translator('Order is not found on selected date'));
            }
        }
        return true;
    }

    public function combineModuleWeekOrders($lunch = [], $guest = [], $meeting = [], $bakery  = [])
    {

        $this_week_array = [];
        foreach (array_keys($lunch+ $guest + $meeting + $bakery) as $day)
        {
            $this_week_array[$day] = [
                'lunch' => isset($lunch[$day]) ? $lunch[$day] : 0,
                'meeting' => isset($meeting[$day]) ? $meeting[$day] : 0,
                'guest' => isset($guest[$day]) ? $guest[$day] : 0,
                'bakery' => isset($bakery[$day]) ? $bakery[$day] : 0,
            ];
        }

        return $this_week_array;
    }



    public function getWeekArray($week_no,$main_order,\DateTime $currentDate)
    {
        $x = $this->getDailyOrders($main_order->getId(),$week_no);

        $orders_arr = $x['week-'.$week_no]['orders'];
        $week_arr = [];
        foreach ($orders_arr as $item)
        {
            if (is_object($item))
            {
                $day_name = \DateTime::createFromFormat('Y-m-d', $item->getOrderDate()); // Convert to DateTime object
                if ($day_name->getTimestamp() > $currentDate->getTimestamp())
                {
                    $day_name = $day_name->format('l');
                    $week_arr[$day_name.' - '.$item->getOrderDate()] =  ($item->getStatus() != 2)? $item->getTotalHeads():0;
                }
            }
            else
            {
                if (@$item['dailyOrderDetails'])
                {
                    $day_name = \DateTime::createFromFormat('Y-m-d', $item['order_date']); // Convert to DateTime object
                    if ($day_name->getTimestamp() > $currentDate->getTimestamp())
                    {
                        $day_name = $day_name->format('l');

                        $week_arr[$day_name.' - '.$item['order_date']] = ($item['status'] != 2)?$item['total_heads']:0;
                    }
                }
            }
        }
        return $week_arr;
    }

    public function getOrSaveDailyOrder($order, $order_date, $save = true)
    {
        $dailyOrder = $this->dailyOrderRepo->findOneBy([
            'order'=> $order,
            'company'=> $order->getCompany(),
            'vendor'=> $order->getVendor(),
            'order_date'=> $order_date
        ]);

        if (!$dailyOrder) {

            $dailyOrder = new DailyOrder();
            $dailyOrder->setOrderDate($order_date);
            $dailyOrder->setOrder($order);
            $dailyOrder->setVendor($order->getVendor());
            $dailyOrder->setCompany($order->getCompany());
            $dailyOrder->setStatus(0);
            $dailyOrder->setTotalHeads(0);
            $dailyOrder->setUpdates(0);
            $dailyOrder->setCancellationDate(null);

            if ($save)
            {
                $this->manager->persist($dailyOrder);
                $this->manager->flush();
            }

            $active_order_details = $order->getOrderDetails();
            $total_heads = 0;
            // dd($active_order_details);
            foreach ($active_order_details as $main_order_detail)
            {
                $dailyOrderDetail = new DailyOrderDetail();
                $dailyOrderDetail->setDailyOrder($dailyOrder);
                $dailyOrderDetail->setDish($main_order_detail->getDish());
                $functionName = 'get' . ucfirst(strtolower($order_date->format('l'))) . 'Heads';

                // Check if the function exists before calling it.
                if (method_exists($main_order_detail, $functionName)) {
                    $heads = call_user_func([$main_order_detail, $functionName]);
                    $dailyOrderDetail->setNoOfHeads($heads);
                    $total_heads = $total_heads + $heads;
                }
                if ($save)
                {
                    $this->manager->persist($dailyOrderDetail);
                    $this->manager->flush();
                }
            }
            $dailyOrder->setTotalHeads($total_heads);
            if ($save)
            {
                $this->manager->persist($dailyOrder);
                $this->manager->flush();
            }
        }

        if ($save)
        {
            $this->manager->refresh($dailyOrder);
        }

        return $dailyOrder;
    }

}